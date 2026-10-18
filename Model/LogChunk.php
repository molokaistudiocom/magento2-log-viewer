<?php

declare(strict_types=1);

namespace MolokaiStudio\LogViewer\Model;

/**
 * Whole lines of a log file between two byte offsets.
 */
readonly class LogChunk
{
    /**
     * @param int $start byte offset of the first line in $content
     * @param int $end byte offset just after the last line in $content
     * @param bool $truncated the file is now shorter than the offset the caller asked to continue from
     *                        (rotated or emptied), so the chunk is a fresh read from the end
     */
    public function __construct(
        public string $content,
        public int $start,
        public int $end,
        public int $fileSize,
        public bool $truncated = false
    ) {
    }
}
