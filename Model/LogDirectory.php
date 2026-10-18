<?php

declare(strict_types=1);

namespace MolokaiStudio\LogViewer\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Exception\ValidatorException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;

/**
 * The only way from a file name taken from a request to a {@see LogFile}.
 *
 * Every controller goes through get(), so the rules live in one place: a *.log name (rotated *.log.N too),
 * a regular file, and a real path inside var/log. The last check is ours rather than the framework's -
 * Magento's PathValidator compares paths lexically, so a symlink inside var/log would pass it while pointing
 * at app/etc/env.php.
 */
class LogDirectory
{
    private const string FILE_NAME_PATTERN = '/\.log(\.\d+)?$/';

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly LogFileFactory $logFileFactory
    ) {
    }

    /**
     * @return LogFile[] most recently modified first
     */
    public function getFiles(): array
    {
        $directory = $this->getDirectory();

        try {
            $paths = $directory->isExist() ? $this->listPaths($directory) : [];
        } catch (FileSystemException|ValidatorException) {
            return [];
        }

        $files = array_values(array_filter(array_map($this->find(...), $paths)));
        usort($files, static fn (LogFile $left, LogFile $right): int
            => [$right->modifiedAt, $left->name] <=> [$left->modifiedAt, $right->name]);

        return $files;
    }

    /**
     * @throws NotFoundException
     */
    public function get(string $name): LogFile
    {
        return $this->find($name) ?? throw new NotFoundException(__('Log file "%1" does not exist.', $name));
    }

    private function find(string $name): ?LogFile
    {
        if ($name === '' || str_contains($name, "\0") || !preg_match(self::FILE_NAME_PATTERN, $name)) {
            return null;
        }

        $directory = $this->getDirectory();

        try {
            if (!$directory->isFile($name)) {
                return null;
            }

            $root = realpath($directory->getAbsolutePath());
            $path = realpath($directory->getAbsolutePath($name));
            if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                return null;
            }

            $stat = $directory->stat($name);
        } catch (FileSystemException|ValidatorException) {
            return null;
        }

        return $this->logFileFactory->create([
            'name' => $name,
            'size' => (int) $stat['size'],
            'modifiedAt' => (int) $stat['mtime'],
        ]);
    }

    /**
     * Every path under var/log, relative to it. ReadInterface has no recursive read of its own.
     *
     * @return string[]
     * @throws FileSystemException
     * @throws ValidatorException
     */
    private function listPaths(ReadInterface $directory, ?string $path = null): array
    {
        $paths = [];
        foreach ($directory->read($path) as $child) {
            $paths[] = $child;
            // A linked directory may lead outside var/log or back into itself; neither is followed.
            if ($directory->isDirectory($child) && !is_link($directory->getAbsolutePath($child))) {
                array_push($paths, ...$this->listPaths($directory, $child));
            }
        }

        return $paths;
    }

    private function getDirectory(): ReadInterface
    {
        return $this->filesystem->getDirectoryRead(DirectoryList::LOG);
    }
}
