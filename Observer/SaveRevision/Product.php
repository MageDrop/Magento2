<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Observer\SaveRevision;

use MageDrop\Magento2\Model\ApplyContext;
use MageDrop\Magento2\Model\Entity\AdapterPool;
use MageDrop\Magento2\Model\Service\ApiClient;
use MageDrop\Magento2\Observer\SaveRevision;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Psr\Log\LoggerInterface;

class Product extends SaveRevision
{
    public function __construct(
        ApiClient $apiClient,
        AdapterPool $adapterPool,
        AuthSession $authSession,
        ApplyContext $applyContext,
        LoggerInterface $logger
    ) {
        parent::__construct($apiClient, $adapterPool, $authSession, $applyContext, $logger, 'catalog_product', true);
    }
}
