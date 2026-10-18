<?php

declare(strict_types=1);

namespace MolokaiStudio\LogViewer\Test\Unit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

trait RemovesDirectoryTree
{
    /**
     * Deletes a temporary fixture directory. Symlinks are removed, never followed, so a link pointing outside
     * the fixture (or back into it) is safe.
     */
    private function removeDirectoryTree(string $path): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($path);
    }
}
