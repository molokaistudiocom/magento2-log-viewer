<?php

declare(strict_types=1);

namespace MolokaiStudio\LogViewer\Model;

/**
 * The last matches of a search, oldest first.
 */
readonly class LogSearchResult
{
    /**
     * @param list<array{line: int, text: string}> $matches
     * @param int $total matches in the searched range, of which $matches holds the last ones
     */
    public function __construct(
        public array $matches,
        public int $total
    ) {
    }

    public function hasEarlierMatches(): bool
    {
        return $this->total > count($this->matches);
    }
}
