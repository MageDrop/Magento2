<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Adminhtml\DataProvider;

use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Framework\App\RequestInterface;
use Psr\Log\LoggerInterface;

class CmsPageLoadChangesPlugin extends LoadChangesPlugin
{
    public function __construct(
        ApiClient $apiClient,
        RequestInterface $request,
        LoggerInterface $logger
    ) {
        parent::__construct($apiClient, $request, $logger, 'cms_page', 'page_id');
    }
}
