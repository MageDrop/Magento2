<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Adminhtml\Stage;

use MageDrop\Magento2\Model\Entity\AdapterPool;
use MageDrop\Magento2\Model\Service\ApiClient;
use MageDrop\Magento2\Model\Staging\Stager;
use MageDrop\Magento2\Plugin\Adminhtml\StageSavePlugin;
use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;

class CategoryStageSavePlugin extends StageSavePlugin
{
    public function __construct(
        Stager $stager,
        AdapterPool $adapterPool,
        ApiClient $apiClient,
        RedirectFactory $redirectFactory,
        ManagerInterface $messageManager,
        BackendSession $backendSession,
        LoggerInterface $logger
    ) {
        parent::__construct(
            $stager,
            $adapterPool,
            $apiClient,
            $redirectFactory,
            $messageManager,
            $backendSession,
            $logger,
            'catalog_category'
        );
    }
}
