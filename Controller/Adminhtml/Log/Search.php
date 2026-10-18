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
 * The last matches of ?q= in a log file as JSON; ?before_line= pages back to earlier matches.
 */
class Search extends Action implements HttpGetActionInterface
{
    public const string ADMIN_RESOURCE = 'MolokaiStudio_LogViewer::logs';

    private const int MIN_QUERY_LENGTH = 2;

    private const int MAX_QUERY_LENGTH = 200;

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

        $query = trim((string) $request->getParam('q'));
        $length = mb_strlen($query);
        if ($length < self::MIN_QUERY_LENGTH || $length > self::MAX_QUERY_LENGTH) {
            return $result->setHttpResponseCode(400)->setData([
                'error' => (string) __(
                    'Enter between %1 and %2 characters.',
                    self::MIN_QUERY_LENGTH,
                    self::MAX_QUERY_LENGTH
                ),
            ]);
        }

        $beforeLine = $request->getParam('before_line');
        $searchResult = $this->logReader->search(
            $file,
            $query,
            $beforeLine !== null ? max(1, (int) $beforeLine) : null
        );

        return $result->setData([
            'matches' => $searchResult->matches,
            'total' => $searchResult->total,
            'hasEarlier' => $searchResult->hasEarlierMatches(),
        ]);
    }
}
