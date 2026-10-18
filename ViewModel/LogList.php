<?php

declare(strict_types=1);

namespace MolokaiStudio\LogViewer\ViewModel;

use DateTimeImmutable;
use IntlDateFormatter;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use MolokaiStudio\LogViewer\Model\LogDirectory;
use MolokaiStudio\LogViewer\Model\LogFile;

class LogList implements ArgumentInterface
{
    private const array SIZE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

    public function __construct(
        private readonly LogDirectory $logDirectory,
        private readonly UrlInterface $urlBuilder,
        private readonly AuthorizationInterface $authorization,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * @return LogFile[]
     */
    public function getFiles(): array
    {
        return $this->logDirectory->getFiles();
    }

    public function getViewUrl(LogFile $file): string
    {
        return $this->urlBuilder->getUrl('molokaistudio_logviewer/log/view', ['_query' => ['file' => $file->name]]);
    }

    public function getDownloadUrl(LogFile $file): string
    {
        return $this->urlBuilder->getUrl('molokaistudio_logviewer/log/download', ['_query' => ['file' => $file->name]]);
    }

    public function canDownload(): bool
    {
        return $this->authorization->isAllowed('MolokaiStudio_LogViewer::download');
    }

    public function formatSize(int $bytes): string
    {
        $unit = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $unit < count(self::SIZE_UNITS) - 1) {
            $size /= 1024;
            $unit++;
        }

        return sprintf($unit === 0 ? '%d %s' : '%.1f %s', $size, self::SIZE_UNITS[$unit]);
    }

    public function formatModifiedAt(LogFile $file): string
    {
        return $this->timezone->formatDateTime(
            new DateTimeImmutable('@' . $file->modifiedAt),
            IntlDateFormatter::MEDIUM,
            IntlDateFormatter::MEDIUM
        );
    }
}
