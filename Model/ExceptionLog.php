<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;

/**
 * The entries added to var/log/exception.log since the last run, read once per run (shared by the exception and email
 * checks): only the new bytes after the stored offset, at most "exceptions/max_read_kb" of them - a larger backlog
 * is skipped and the count marked as a minimum. A smaller file or another inode (rotation) starts from its
 * beginning; the first run only stores the offset. An entry is counted and its exception class names taken
 * ("[object] (Class(code: ..."); nothing else of a line is kept, logged or sent.
 */
class ExceptionLog
{
    public const FILE = 'exception.log';
    private const CURSOR = 'cursor:exception_log';
    private const CLASS_NAME = '/\[object\] \(([A-Za-z_][A-Za-z0-9_\\\\]{0,119})\(code: /';
    /** Mail transport failures: Magento's MailException, Symfony Mailer and Laminas Mail exceptions. */
    private const MAIL_CLASS = '/(^|\\\\)MailException$|^Symfony\\\\Component\\\\Mailer\\\\|^Laminas\\\\Mail\\\\/';

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly RunState $state,
        private readonly Config $config
    ) {
    }

    /**
     * @return array{entries: int, classes: array<string, int>, mail: int, partial: bool} entries, the first class
     *     of each by count ("other" without one), entries with a mail transport class, and whether a backlog over the
     *     cap was skipped
     */
    public function newEntries(): array
    {
        return $this->state->memo(self::class, fn () => $this->read());
    }

    /** @return array{entries: int, classes: array<string, int>, mail: int, partial: bool} */
    private function read(): array
    {
        $none = ['entries' => 0, 'classes' => [], 'mail' => 0, 'partial' => false];
        $dir = $this->filesystem->getDirectoryRead(DirectoryList::LOG);
        if (!$dir->isFile(self::FILE)) {
            $this->state->set(self::CURSOR, ['offset' => 0, 'inode' => 0]);
            return $none;
        }
        $stat = $dir->stat(self::FILE);
        [$size, $inode] = [(int)($stat['size'] ?? 0), (int)($stat['ino'] ?? 0)];
        $cursor = $this->state->get(self::CURSOR);
        if ($cursor === null) {
            $this->state->set(self::CURSOR, ['offset' => $size, 'inode' => $inode]);
            return $none;
        }
        $offset = (int)($cursor['offset'] ?? 0);
        if ($size < $offset || $inode !== (int)($cursor['inode'] ?? 0)) {
            $offset = 0; // rotated or truncated: the new file from its start
        }
        if ($size === $offset) {
            $this->state->set(self::CURSOR, ['offset' => $offset, 'inode' => $inode]);
            return $none;
        }
        $cap = $this->config->getInt('exceptions', 'max_read_kb', 1024) * 1024;
        $length = min($size - $offset, $cap);
        $file = $dir->openFile(self::FILE);
        try {
            $file->seek($offset);
            $data = (string)$file->read($length);
        } finally {
            $file->close();
        }
        $partial = $size - $offset > $cap;
        if (!$partial) {
            // a half-written last entry is read with the next run
            $end = strrpos($data, "\n");
            $data = $end === false ? '' : substr($data, 0, $end + 1);
        }
        $this->state->set(self::CURSOR, ['offset' => $partial ? $size : $offset + strlen($data), 'inode' => $inode]);
        return $this->parse($data) + ['partial' => $partial];
    }

    /** @return array{entries: int, classes: array<string, int>, mail: int} */
    private function parse(string $data): array
    {
        $result = ['entries' => 0, 'classes' => [], 'mail' => 0];
        $entries = preg_split('/^(?=\[\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2})/m', $data, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($entries as $entry) {
            if (!preg_match('/^\[\d{4}-\d{2}-\d{2}/', $entry)) {
                continue; // the rest of an entry that started before the offset
            }
            $result['entries']++;
            preg_match_all(self::CLASS_NAME, $entry, $matches);
            $classes = array_map(fn (string $class) => str_replace('\\\\', '\\', $class), $matches[1]);
            $first = $classes[0] ?? 'other';
            $result['classes'][$first] = ($result['classes'][$first] ?? 0) + 1;
            foreach ($classes as $class) {
                if (preg_match(self::MAIL_CLASS, $class)) {
                    $result['mail']++;
                    break;
                }
            }
        }
        return $result;
    }
}
