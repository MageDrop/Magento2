<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Controller\Adminhtml\Stage;

use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

class LoadChanges extends Action
{
    private const ENTITY_CONFIG = [
        'cms_page' => ['editRoute' => 'cms/page/edit', 'idParam' => 'page_id'],
        'cms_block' => ['editRoute' => 'cms/block/edit', 'idParam' => 'block_id'],
    ];

    public function __construct(
        Context $context,
        private ApiClient $apiClient,
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
        $entityType = $request->getParam('entity_type', '');
        $entityId = $request->getParam('entity_id', '');

        if (!$releaseId || !$entityType || !$entityId) {
            return $result->setData(['success' => false, 'message' => 'Missing required parameters']);
        }

        try {
            $changes = $this->apiClient->getPreviewChanges($releaseId, $entityType, $entityId);

            if (empty($changes)) {
                return $result->setData(['success' => false, 'message' => 'No staged changes found for this entity in the selected release.']);
            }

            $entityConfig = self::ENTITY_CONFIG[$entityType] ?? null;
            $redirectUrl = $entityConfig
                ? $this->url->getUrl($entityConfig['editRoute'], [$entityConfig['idParam'] => $entityId, 'magedrop_load' => $releaseId])
                : '';

            return $result->setData([
                'success' => true,
                'count' => count($changes),
                'redirect_url' => $redirectUrl,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Load changes error: ' . $e->getMessage());
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('MageDrop_Magento2::releases');
    }
}
