<?php

declare(strict_types=1);

namespace MolokaiStudio\LogViewer\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NotFoundException;
use MolokaiStudio\LogViewer\Model\LogDirectory;

/**
 * Sends a whole log file. Separate ACL resource: a download leaves the admin with everything in it.
 */
class Download extends Action implements HttpGetActionInterface
{
    public const string ADMIN_RESOURCE = 'MolokaiStudio_LogViewer::download';

    public function __construct(
        Context $context,
        private readonly LogDirectory $logDirectory,
        private readonly FileFactory $fileFactory
    ) {
        parent::__construct($context);
    }

    #[\Override]
    public function execute(): ResponseInterface|Redirect
    {
        try {
            $file = $this->logDirectory->get((string) $this->getRequest()->getParam('file'));
        } catch (NotFoundException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());

            /** @var Redirect $redirect */
            $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

            return $redirect->setPath('*/*/index');
        }

        // FileFactory streams the file from disk in small blocks rather than loading it.
        return $this->fileFactory->create(
            basename($file->name),
            ['type' => 'filename', 'value' => $file->name],
            DirectoryList::LOG,
            'text/plain'
        );
    }
}
