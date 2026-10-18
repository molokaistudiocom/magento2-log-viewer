<?php

declare(strict_types=1);

namespace MolokaiStudio\LogViewer\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NotFoundException;
use MolokaiStudio\LogViewer\Model\LogDirectory;
use MolokaiStudio\LogViewer\Model\LogReader;

/**
 * A piece of a log file as JSON: older lines with ?before=<offset>, new lines with ?after=<offset>,
 * the tail with neither.
 */
class Chunk extends Action implements HttpGetActionInterface
{
    public const string ADMIN_RESOURCE = 'MolokaiStudio_LogViewer::logs';

    public function __construct(
        Context $context,
        private readonly LogDirectory $logDirectory,
        private readonly LogReader $logReader
    ) {
        parent::__construct($context);
    }

    #[\Override]
    public function execute(): Json
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $request = $this->getRequest();

        try {
            $file = $this->logDirectory->get((string) $request->getParam('file'));
        } catch (NotFoundException $exception) {
            return $result->setHttpResponseCode(404)->setData(['error' => $exception->getMessage()]);
        }

        $after = $request->getParam('after');
        $before = $request->getParam('before');
        $chunk = $after !== null
            ? $this->logReader->readAfter($file, max(0, (int) $after))
            : $this->logReader->readBefore($file, $before !== null ? max(0, (int) $before) : null);

        return $result->setData([
            'content' => $chunk->content,
            'start' => $chunk->start,
            'end' => $chunk->end,
            'fileSize' => $chunk->fileSize,
            'truncated' => $chunk->truncated,
        ]);
    }
}
