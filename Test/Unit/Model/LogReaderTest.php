<?php

declare(strict_types=1);

namespace MolokaiStudio\LogViewer\Test\Unit\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\Read;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Filesystem\DriverPool;
use Magento\Framework\Filesystem\File\ReadFactory;
use MolokaiStudio\LogViewer\Model\LogChunk;
use MolokaiStudio\LogViewer\Model\LogChunkFactory;
use MolokaiStudio\LogViewer\Model\LogFile;
use MolokaiStudio\LogViewer\Model\LogReader;
use MolokaiStudio\LogViewer\Model\LogSearchResult;
use MolokaiStudio\LogViewer\Model\LogSearchResultFactory;
use MolokaiStudio\LogViewer\Test\Unit\RemovesDirectoryTree;
use PHPUnit\Framework\TestCase;

class LogReaderTest extends TestCase
{
    use RemovesDirectoryTree;

    private const int CHUNK_BYTES = 65536;

    private string $logDir;
    private LogReader $reader;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/molokaistudio_logreader_' . bin2hex(random_bytes(4));
        mkdir($this->logDir);

        $directory = new Read(new ReadFactory(new DriverPool()), new File(), $this->logDir);
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryRead')->with(DirectoryList::LOG)->willReturn($directory);

        $chunkFactory = $this->createMock(LogChunkFactory::class);
        $chunkFactory->method('create')->willReturnCallback(static fn (array $data): LogChunk => new LogChunk(...$data));
        $searchFactory = $this->createMock(LogSearchResultFactory::class);
        $searchFactory->method('create')
            ->willReturnCallback(static fn (array $data): LogSearchResult => new LogSearchResult(...$data));

        $this->reader = new LogReader($filesystem, $chunkFactory, $searchFactory);
    }

    protected function tearDown(): void
    {
        $this->removeDirectoryTree($this->logDir);
    }

    public function testReadsASmallFileWhole(): void
    {
        $chunk = $this->reader->readBefore($this->createLog("one\ntwo\n"));

        self::assertSame("one\ntwo\n", $chunk->content);
        self::assertSame(0, $chunk->start);
        self::assertSame(8, $chunk->end);
    }

    public function testTailStartsOnALineBoundaryAndPagesBackWithoutGapsOrOverlap(): void
    {
        $content = $this->numberedLines(20000);
        $file = $this->createLog($content);

        $tail = $this->reader->readBefore($file);
        self::assertGreaterThan(0, $tail->start);
        self::assertLessThanOrEqual(self::CHUNK_BYTES, strlen($tail->content));
        self::assertSame("\n", $content[$tail->start - 1]);
        self::assertSame(substr($content, $tail->start), $tail->content);

        $collected = $tail->content;
        $before = $tail->start;
        while ($before > 0) {
            $page = $this->reader->readBefore($file, $before);
            self::assertSame($before, $page->end);
            $collected = $page->content . $collected;
            $before = $page->start;
        }

        self::assertSame($content, $collected);
    }

    public function testFollowReturnsOnlyCompleteNewLines(): void
    {
        $file = $this->createLog("first\nsecond\nhalf a li");

        $chunk = $this->reader->readAfter($file, 6);

        self::assertSame("second\n", $chunk->content);
        self::assertSame(6, $chunk->start);
        self::assertSame(13, $chunk->end);
        self::assertFalse($chunk->truncated);
    }

    public function testFollowWithNothingNewReturnsAnEmptyChunkAtTheSameOffset(): void
    {
        $chunk = $this->reader->readAfter($this->createLog("first\n"), 6);

        self::assertSame('', $chunk->content);
        self::assertSame(6, $chunk->end);
    }

    public function testFollowPastTheEndOfARotatedFileStartsOverFromTheTail(): void
    {
        $chunk = $this->reader->readAfter($this->createLog("fresh\n"), 5000);

        self::assertTrue($chunk->truncated);
        self::assertSame("fresh\n", $chunk->content);
        self::assertSame(6, $chunk->end);
    }

    public function testInvalidUtf8IsReplacedSoTheJsonEncoderAcceptsIt(): void
    {
        $chunk = $this->reader->readBefore($this->createLog("bad \xC3\x28 byte\n"));

        self::assertNotFalse(json_encode($chunk->content));
        self::assertTrue(mb_check_encoding($chunk->content, 'UTF-8'));
    }

    public function testSearchIsCaseInsensitiveAndReportsLineNumbers(): void
    {
        $file = $this->createLog("alpha\nmain.ERROR: boom\nbeta\nmain.error: again\nlast line without newline error");

        $result = $this->reader->search($file, 'Error');

        self::assertSame(
            [
                ['line' => 2, 'text' => 'main.ERROR: boom'],
                ['line' => 4, 'text' => 'main.error: again'],
                ['line' => 5, 'text' => 'last line without newline error'],
            ],
            $result->matches
        );
        self::assertSame(3, $result->total);
        self::assertFalse($result->hasEarlierMatches());
    }

    public function testSearchKeepsTheLastHundredMatchesAndPagesBackByLineNumber(): void
    {
        // 300 000 lines, well over one 1 MB read block, every third line matching.
        $lines = [];
        for ($i = 1; $i <= 300000; $i++) {
            $lines[] = $i % 3 === 0 ? "line $i needle" : "line $i hay";
        }
        $file = $this->createLog(implode("\n", $lines) . "\n");

        $last = $this->reader->search($file, 'needle');
        self::assertSame(100000, $last->total);
        self::assertCount(100, $last->matches);
        self::assertSame(['line' => 300000, 'text' => 'line 300000 needle'], $last->matches[99]);
        self::assertSame(['line' => 299703, 'text' => 'line 299703 needle'], $last->matches[0]);
        self::assertTrue($last->hasEarlierMatches());

        $earlier = $this->reader->search($file, 'needle', 299703);
        self::assertSame(99900, $earlier->total);
        self::assertSame(['line' => 299700, 'text' => 'line 299700 needle'], $earlier->matches[99]);
    }

    private function createLog(string $content): LogFile
    {
        file_put_contents($this->logDir . '/test.log', $content);

        return new LogFile('test.log', strlen($content), time());
    }

    private function numberedLines(int $count): string
    {
        $content = '';
        for ($i = 1; $i <= $count; $i++) {
            $content .= sprintf("[2026-10-18 12:00:00] main.INFO: line %d zażółć\n", $i);
        }

        return $content;
    }
}
