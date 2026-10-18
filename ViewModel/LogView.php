<?php

declare(strict_types=1);

namespace MolokaiStudio\LogViewer\ViewModel;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * The View controller has already checked the file name; the page only passes it on to the AJAX actions,
 * which check it again.
 */
class LogView implements ArgumentInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly UrlInterface $urlBuilder,
        private readonly AuthorizationInterface $authorization
    ) {
    }

    public function getFileName(): string
    {
        return (string) $this->request->getParam('file');
    }

    public function getBackUrl(): string
    {
        return $this->urlBuilder->getUrl('molokaistudio_logviewer/log/index');
    }

    public function getDownloadUrl(): ?string
    {
        if (!$this->authorization->isAllowed('MolokaiStudio_LogViewer::download')) {
            return null;
        }

        return $this->urlBuilder->getUrl(
            'molokaistudio_logviewer/log/download',
            ['_query' => ['file' => $this->getFileName()]]
        );
    }

    /**
     * @return array{file: string, chunkUrl: string, searchUrl: string}
     */
    public function getWidgetConfig(): array
    {
        return [
            'file' => $this->getFileName(),
            'chunkUrl' => $this->urlBuilder->getUrl('molokaistudio_logviewer/log/chunk'),
            'searchUrl' => $this->urlBuilder->getUrl('molokaistudio_logviewer/log/search'),
        ];
    }
}
