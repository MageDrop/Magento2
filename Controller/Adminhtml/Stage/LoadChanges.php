<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Controller\Adminhtml\Stage;

use MageDrop\Magento2\Model\Entity\AdapterPool;
use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

/**
 * AJAX: does the selected release hold staged changes for this entity? If so,
 * return the edit URL that will load them (LoadChangesPlugin does the merge).
 */
class LoadChanges extends Action
{
    public function __construct(
        Context $context,
        private ApiClient $apiClient,
        private AdapterPool $adapterPool,
        private JsonFactory $jsonFactory,
        private UrlInterface $url,
        private LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $request = $this->getRequest();

        $releaseId = (int) $request->getParam('release_id', 0);
        $entityType = (string) $request->getParam('entity_type', '');
        $entityId = (string) $request->getParam('entity_id', '');
        $storeId = (int) $request->getParam('store_id', 0);

        if (!$releaseId || !$entityType || !$entityId) {
            return $result->setData(['success' => false, 'message' => 'Missing required parameters']);
        }

        try {
            $adapter = $this->adapterPool->get($entityType);
            $groups = $this->apiClient->getPreviewChanges($releaseId, $entityType, $entityId);

            $count = 0;
            foreach ($groups as $group) {
                $scope = (int) ($group['scope_store_id'] ?? 0);
                if ($scope === 0 || $scope === $storeId) {
                    $count += count($group['changes']);
                }
            }

            if ($count === 0) {
                return $result->setData([
                    'success' => false,
                    'message' => 'No staged changes found for this entity in the selected release.',
                ]);
            }

            $params = $adapter->getEditParams($entityId, $storeId) + ['magedrop_load' => $releaseId];

            return $result->setData([
                'success' => true,
                'count' => $count,
                'redirect_url' => $this->url->getUrl($adapter->getEditRoute(), $params),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Load changes error: ' . $e->getMessage());

            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('MageDrop_Magento2::releases');
    }
}
