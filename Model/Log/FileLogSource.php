<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Log;

use CommerceKings\AppHealth\Api\Data\LogChunk;
use CommerceKings\AppHealth\Api\LogSourceInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;

/**
 * A file in var/log, named by $file or by the setting at $fileConfigPath (a plain name like "system.log", checked:
 * no path, no debug.log). Only the bytes after the cursor's offset, at most $maxBytes - of a larger backlog the newest
 * $maxBytes, from the first whole line (partial, with the bytes skipped). A smaller file or another inode (rotation)
 * starts from its beginning; the first read only takes the offset; a half-written last line waits for the next read.
 */
class FileLogSource implements LogSourceInterface
{
    /** A log file name in var/log; debug.log stays out. */
    public const NAME = '/^(?!debug\.log$)[A-Za-z0-9][A-Za-z0-9._-]{0,60}\.log$/D';

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly string $file = '',
        private readonly string $fileConfigPath = ''
    ) {
    }

    /** The file name, or '' when it is not a valid one. */
    public function getFile(): string
    {
        $file = $this->fileConfigPath !== ''
            ? trim((string)$this->scopeConfig->getValue($this->fileConfigPath)) : $this->file;
        return preg_match(self::NAME, $file) ? $file : '';
    }

    public function read(array $cursor, int $maxBytes): LogChunk
    {
        $file = $this->getFile();
        $dir = $this->filesystem->getDirectoryRead(DirectoryList::LOG);
        if ($file === '' || !$dir->isFile($file)) {
            return new LogChunk('', ['offset' => 0, 'inode' => 0]);
        }
        $stat = $dir->stat($file);
        [$size, $inode] = [(int)($stat['size'] ?? 0), (int)($stat['ino'] ?? 0)];
        if (!isset($cursor['offset'])) {
            return new LogChunk('', ['offset' => $size, 'inode' => $inode]);
        }
        $offset = (int)$cursor['offset'];
        if ($size < $offset || $inode !== (int)($cursor['inode'] ?? 0)) {
            $offset = 0; // rotated or truncated: the new file from its start
        }
        if ($size === $offset) {
            return new LogChunk('', ['offset' => $offset, 'inode' => $inode]);
        }
        $partial = $size - $offset > max(1, $maxBytes);
        $start = $partial ? $size - max(1, $maxBytes) : $offset; // a backlog: its newest bytes
        $handle = $dir->openFile($file);
        try {
            $handle->seek($start);
            $data = (string)$handle->read($size - $start);
        } finally {
            $handle->close();
        }
        $from = $start; // where $data starts in the file
        if ($partial) {
            $first = strpos($data, "\n"); // the line cut by the start
            $cut = $first === false ? strlen($data) : $first + 1;
            $data = substr($data, $cut);
            $from += $cut;
        }
        $end = strrpos($data, "\n"); // a half-written last line waits for the next read
        $data = $end === false ? '' : substr($data, 0, $end + 1);
        $next = $partial && $data === '' ? $size : $from + strlen($data);
        return new LogChunk($data, ['offset' => $next, 'inode' => $inode], $partial, $from - $offset);
    }
}
