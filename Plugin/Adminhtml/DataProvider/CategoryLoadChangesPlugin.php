<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Adminhtml\DataProvider;

use MageDrop\Magento2\Model\Entity\AdapterPool;
use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Framework\App\RequestInterface;
use Psr\Log\LoggerInterface;

class CategoryLoadChangesPlugin extends LoadChangesPlugin
{
    public function __construct(
        ApiClient $apiClient,
        AdapterPool $adapterPool,
        RequestInterface $request,
        LoggerInterface $logger
    ) {
        parent::__construct($apiClient, $adapterPool, $request, $logger, 'catalog_category');
    }
}
