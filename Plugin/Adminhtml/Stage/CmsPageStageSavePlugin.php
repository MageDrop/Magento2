<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Adminhtml\Stage;

use MageDrop\Magento2\Model\Service\ApiClient;
use MageDrop\Magento2\Plugin\Adminhtml\StageSavePlugin;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;

class CmsPageStageSavePlugin extends StageSavePlugin
{
    public function __construct(
        ApiClient $apiClient,
        RedirectFactory $redirectFactory,
        ManagerInterface $messageManager,
        LoggerInterface $logger
    ) {
        parent::__construct($apiClient, $redirectFactory, $messageManager, $logger, 'cms_page', 'page_id', 'cms/page/edit');
    }
}
