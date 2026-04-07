<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Observer\CmsSaveRevision;

use MageDrop\Magento2\Model\Service\ApiClient;
use MageDrop\Magento2\Observer\CmsSaveRevision;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Psr\Log\LoggerInterface;

class CmsBlock extends CmsSaveRevision
{
    public function __construct(
        ApiClient $apiClient,
        AuthSession $authSession,
        LoggerInterface $logger
    ) {
        parent::__construct($apiClient, $authSession, $logger, 'cms_block', 'block_id');
    }
}
