<?php

declare(strict_types=1);

namespace MolokaiStudio\LogViewer\Test\Unit\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\Read;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Filesystem\DriverPool;
use Magento\Framework\Filesystem\File\ReadFactory;
use MolokaiStudio\LogViewer\Model\LogDirectory;
use MolokaiStudio\LogViewer\Model\LogFile;
use MolokaiStudio\LogViewer\Model\LogFileFactory;
use MolokaiStudio\LogViewer\Test\Unit\RemovesDirectoryTree;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LogDirectoryTest extends TestCase
{
    use RemovesDirectoryTree;

    private string $root;
    private string $logDir;
    private LogDirectory $logDirectory;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/molokaistudio_logviewer_' . bin2hex(random_bytes(4));
        $this->logDir = $this->root . '/var/log';
        mkdir($this->logDir . '/akeneo_connector', 0777, true);
        mkdir($this->logDir . '/folder.log');
        file_put_contents($this->root . '/env.php', "<?php return ['crypt' => 'secret'];");
        file_put_contents($this->root . '/outside.log', 'outside');

        $this->write('system.log', "old\n", 1000);
        $this->write('exception.log', "new\n", 3000);
        $this->write('akeneo_connector/import.log', "nested\n", 2000);
        $this->write('system.log.1', "rotated\n", 500);
        $this->write('notes.txt', "not a log\n", 4000);
        symlink($this->root . '/outside.log', $this->logDir . '/linked.log');
        symlink($this->logDir, $this->logDir . '/akeneo_connector/loop');

        $driver = new File();
        $directory = new Read(new ReadFactory(new DriverPool()), $driver, $this->logDir);
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryRead')->with(DirectoryList::LOG)->willReturn($directory);

        $factory = $this->createMock(LogFileFactory::class);
        $factory->method('create')->willReturnCallback(static fn (array $data): LogFile => new LogFile(...$data));

        $this->logDirectory = new LogDirectory($filesystem, $factory);
    }

    protected function tearDown(): void
    {
        $this->removeDirectoryTree($this->root);
    }

    public function testListsLogFilesNewestFirstIncludingSubdirectoriesAndRotatedFiles(): void
    {
        $names = array_map(static fn (LogFile $file): string => $file->name, $this->logDirectory->getFiles());

        self::assertSame(['exception.log', 'akeneo_connector/import.log', 'system.log', 'system.log.1'], $names);
    }

    public function testResolvesAFileWithItsSizeAndModificationTime(): void
    {
        $file = $this->logDirectory->get('akeneo_connector/import.log');

        self::assertSame('akeneo_connector/import.log', $file->name);
        self::assertSame(7, $file->size);
        self::assertSame(2000, $file->modifiedAt);
    }

    #[DataProvider('rejectedNames')]
    public function testRejectsNamesOutsideTheLogFiles(string $name): void
    {
        $this->expectException(NotFoundException::class);

        $this->logDirectory->get($name);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedNames(): array
    {
        return [
            'traversal to env.php' => ['../../env.php'],
            'traversal to a .log name' => ['../../outside.log'],
            'absolute path' => ['/etc/passwd'],
            'null byte' => ["../../env.php\0.log"],
            'symlink leaving var/log' => ['linked.log'],
            'not a log extension' => ['notes.txt'],
            'directory named like a log' => ['folder.log'],
            'missing file' => ['missing.log'],
            'empty name' => [''],
        ];
    }

    private function write(string $name, string $content, int $mtime): void
    {
        file_put_contents($this->logDir . '/' . $name, $content);
        touch($this->logDir . '/' . $name, $mtime);
    }
}
