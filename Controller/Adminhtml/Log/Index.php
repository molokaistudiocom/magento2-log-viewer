<?php

declare(strict_types=1);

namespace MolokaiStudio\LogViewer\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * List of the files in var/log.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const string ADMIN_RESOURCE = 'MolokaiStudio_LogViewer::logs';

    #[\Override]
    public function execute(): Page
    {
        /** @var Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('MolokaiStudio_LogViewer::logs');
        $page->getConfig()->getTitle()->prepend((string) __('Log Viewer'));

        return $page;
    }
}
