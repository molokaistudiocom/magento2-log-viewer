<?php

declare(strict_types=1);

namespace MolokaiStudio\LogViewer\Model;

use Generator;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\File\ReadInterface as FileReadInterface;

/**
 * Reads log files in bounded pieces, so a multi-gigabyte production log costs the same as a small one.
 *
 * Positions are byte offsets in the file, handed to the browser and back. They are never derived from what
 * the browser has displayed - character counts drift from byte counts on the first multibyte character.
 * Every string leaving this class is valid UTF-8, since logs hold arbitrary bytes and the JSON encoder
 * rejects anything else.
 */
class LogReader
{
    /** One page of "load older": a few hundred typical lines. */
    private const int CHUNK_BYTES = 65536;

    /** Upper bound of one live-follow poll; a log growing faster than this just takes a few polls to catch up. */
    private const int FOLLOW_BYTES = 262144;

    private const int SEARCH_BLOCK_BYTES = 1048576;

    /** A line without a newline is cut here rather than buffered whole. */
    private const int SEARCH_MAX_LINE_BYTES = 8388608;

    private const int SEARCH_MATCH_LIMIT = 100;

    private const int SEARCH_MATCH_DISPLAY_BYTES = 2000;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly LogChunkFactory $logChunkFactory,
        private readonly LogSearchResultFactory $logSearchResultFactory
    ) {
    }

    /**
     * The whole lines that end at or before $before - the tail of the file when $before is null.
     */
    public function readBefore(LogFile $file, ?int $before = null): LogChunk
    {
        $end = min($before ?? $file->size, $file->size);
        $start = max(0, $end - self::CHUNK_BYTES);
        $content = $this->read($file, $start, $end - $start);

        // Unless the chunk reaches the start of the file it begins mid-line; that line belongs to the next
        // page. A chunk with no newline at all is one enormous line and is shown as it is.
        $newline = $start > 0 ? strpos($content, "\n") : false;
        if ($newline !== false) {
            $start += $newline + 1;
            $content = substr($content, $newline + 1);
        }

        return $this->createChunk($content, $start, $end, $file->size);
    }

    /**
     * The whole lines written after $after. A line still being written is left for the next poll.
     */
    public function readAfter(LogFile $file, int $after): LogChunk
    {
        if ($after > $file->size) {
            $chunk = $this->readBefore($file);

            return $this->createChunk($chunk->content, $chunk->start, $chunk->end, $file->size, true);
        }

        $length = min(self::FOLLOW_BYTES, $file->size - $after);
        $content = $this->read($file, $after, $length);

        $lastNewline = strrpos($content, "\n");
        if ($lastNewline !== false) {
            $content = substr($content, 0, $lastNewline + 1);
        } elseif ($length < self::FOLLOW_BYTES) {
            $content = '';
        }

        return $this->createChunk($content, $after, $after + strlen($content), $file->size);
    }

    /**
     * Case-insensitive substring search over the whole file, or over the lines before $beforeLine.
     */
    public function search(LogFile $file, string $query, ?int $beforeLine = null): LogSearchResult
    {
        $matches = [];
        $total = 0;

        foreach ($this->findLines($file, $query) as $lineNumber => $line) {
            if ($beforeLine !== null && $lineNumber >= $beforeLine) {
                break;
            }

            $total++;
            $matches[] = [
                'line' => $lineNumber,
                'text' => $this->toUtf8(mb_strcut($line, 0, self::SEARCH_MATCH_DISPLAY_BYTES, '8bit')),
            ];
            if (count($matches) > self::SEARCH_MATCH_LIMIT) {
                array_shift($matches);
            }
        }

        return $this->logSearchResultFactory->create(['matches' => $matches, 'total' => $total]);
    }

    /**
     * Streams the file in blocks; a block without the query only has its newlines counted.
     *
     * @return Generator<int, string> 1-based line number => line, for the lines that contain $query
     */
    private function findLines(LogFile $file, string $query): Generator
    {
        $handle = $this->open($file);
        $lineNumber = 0;
        $carry = '';

        try {
            while (!$handle->eof()) {
                $buffer = $carry . $handle->read(self::SEARCH_BLOCK_BYTES);
                $lastNewline = strrpos($buffer, "\n");

                if ($lastNewline === false) {
                    $carry = $buffer;
                    if (strlen($carry) < self::SEARCH_MAX_LINE_BYTES) {
                        continue;
                    }
                    $buffer .= "\n";
                    $lastNewline = strlen($buffer) - 1;
                }

                $carry = substr($buffer, $lastNewline + 1);
                $block = substr($buffer, 0, $lastNewline);

                if (stripos($block, $query) === false) {
                    $lineNumber += substr_count($block, "\n") + 1;
                    continue;
                }

                foreach (explode("\n", $block) as $line) {
                    $lineNumber++;
                    if (stripos($line, $query) !== false) {
                        yield $lineNumber => $line;
                    }
                }
            }

            if ($carry !== '' && stripos($carry, $query) !== false) {
                yield $lineNumber + 1 => $carry;
            }
        } finally {
            $handle->close();
        }
    }

    private function read(LogFile $file, int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $handle = $this->open($file);

        try {
            $handle->seek($offset);

            return (string) $handle->read($length);
        } finally {
            $handle->close();
        }
    }

    private function open(LogFile $file): FileReadInterface
    {
        return $this->filesystem->getDirectoryRead(DirectoryList::LOG)->openFile($file->name);
    }

    private function createChunk(string $content, int $start, int $end, int $fileSize, bool $truncated = false): LogChunk
    {
        return $this->logChunkFactory->create([
            'content' => $this->toUtf8($content),
            'start' => $start,
            'end' => $end,
            'fileSize' => $fileSize,
            'truncated' => $truncated,
        ]);
    }

    private function toUtf8(string $text): string
    {
        return mb_scrub($text, 'UTF-8');
    }
}
