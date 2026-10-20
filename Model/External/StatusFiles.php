<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\External;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;

/**
 * The servers' status files: var/app-health/status-<source>.json, written by a script on each server (see
 * docs/examples/host-status.sh) - a temporary file renamed into place, so a file is read whole or not at all.
 * Anything else in the folder (the script's temporary files, status.json.dist) is not a status file.
 *
 * The folder and status.json.dist (the format, to copy from) are created when they are missing, with Magento's
 * permissions for var/, so the script on the server can write next to them.
 */
class StatusFiles
{
    public const DIR = 'app-health';
    public const NAME = '/^status-([A-Za-z0-9._-]{1,40})\.json$/D';
    public const DIST = 'status.json.dist';
    public const MAX_FILES = 20;
    public const MAX_BYTES = 32768;

    public function __construct(
        private readonly Filesystem $filesystem
    ) {
    }

    /**
     * Up to MAX_FILES status files by name; an oversized file as null, never read.
     *
     * @return array{files: array<string, array{source: string, json: ?string}>, more: int} by file name, and how
     *     many status files were left out (over MAX_FILES)
     * @throws FileSystemException when var/app-health can't be created or read
     */
    public function read(): array
    {
        $var = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        if (!$var->isExist(self::DIR)) {
            $var->create(self::DIR);
        }
        if (!$var->isExist(self::DIR . '/' . self::DIST)) {
            try {
                $var->writeFile(self::DIR . '/' . self::DIST, self::example());
            } catch (FileSystemException) {
                // a folder the server's script created, not writable for Magento: the files can still be read
            }
        }
        $names = [];
        foreach ($var->read(self::DIR) as $path) {
            $name = basename($path);
            if (preg_match(self::NAME, $name, $match)) {
                $names[$name] = $match[1];
            }
        }
        ksort($names);
        $files = [];
        foreach (array_slice($names, 0, self::MAX_FILES, true) as $name => $source) {
            $path = self::DIR . '/' . $name;
            try {
                $json = (int)($var->stat($path)['size'] ?? 0) > self::MAX_BYTES ? null : $var->readFile($path);
            } catch (FileSystemException) {
                continue; // renamed away between the listing and the read: it is not there
            }
            $files[$name] = ['source' => $source, 'json' => $json !== null && strlen($json) <= self::MAX_BYTES
                ? $json : null];
        }
        return ['files' => $files, 'more' => max(0, count($names) - self::MAX_FILES)];
    }

    /** status.json.dist: the format, every field filled with what it takes. */
    public static function example(): string
    {
        return json_encode(['report' => [
            'version' => 1,
            'source' => 'the server: A-Z a-z 0-9 . _ - (at most 40); the file is status-<source>.json',
            'generated_at' => 'when the script ran, ISO-8601 with a time zone, e.g. 2026-10-20T14:05:00Z',
            'checks' => [[
                'code' => 'disk_root',
                'label' => 'Disk / (at most 60 characters; the code when empty)',
                'status' => 'ok, warn or fail',
                'value' => 87,
                'unit' => '% (at most 10 characters)',
                'detail' => 'one line of names and numbers, at most 160 characters; at most 50 checks',
            ]],
        ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
