<?php

declare(strict_types=1);

namespace MolokaiStudio\LogViewer\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NotFoundException;
use MolokaiStudio\LogViewer\Model\LogDirectory;

/**
 * One log file. The page itself is an empty frame; the content is fetched by the Chunk and Search actions.
 */
class View extends Action implements HttpGetActionInterface
{
    public const string ADMIN_RESOURCE = 'MolokaiStudio_LogViewer::logs';

    public function __construct(
        Context $context,
        private readonly LogDirectory $logDirectory
    ) {
        parent::__construct($context);
    }

    #[\Override]
    public function execute(): Page|Redirect
    {
        try {
            $file = $this->logDirectory->get((string) $this->getRequest()->getParam('file'));
        } catch (NotFoundException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());

            /** @var Redirect $redirect */
            $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

            return $redirect->setPath('*/*/index');
        }

        /** @var Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('MolokaiStudio_LogViewer::logs');
        $page->getConfig()->getTitle()->prepend((string) __('Log Viewer: %1', $file->name));

        return $page;
    }
}
