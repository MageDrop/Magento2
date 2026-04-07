<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Controller\Adminhtml\Stage;

use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

class SaveStage extends Action
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
        $formData = $request->getParam('form_data', []);

        if (!$releaseId || !$entityType || !$entityId || empty($formData)) {
            return $result->setData(['success' => false, 'message' => 'Missing required parameters.']);
        }

        try {
            $response = $this->apiClient->stageEntity($releaseId, $entityType, $entityId, $formData);

            if (empty($response)) {
                return $result->setData(['success' => false, 'message' => 'Failed to communicate with MageDrop API.']);
            }

            if (!empty($response['error'])) {
                return $result->setData(['success' => false, 'message' => $response['error']]);
            }

            $changeCount = $response['change_count'] ?? 0;
            $releaseName = $response['release'] ?? '';

            if ($changeCount === 0) {
                return $result->setData([
                    'success' => true,
                    'staged' => false,
                    'message' => 'No changes detected — the form data matches what is already live.',
                ]);
            }

            $entityConfig = self::ENTITY_CONFIG[$entityType] ?? null;
            $redirectUrl = $entityConfig
                ? $this->url->getUrl($entityConfig['editRoute'], [$entityConfig['idParam'] => $entityId, 'magedrop_load' => $releaseId])
                : '';

            return $result->setData([
                'success' => true,
                'staged' => true,
                'change_count' => $changeCount,
                'release_name' => $releaseName,
                'redirect_url' => $redirectUrl,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('SaveStage error: ' . $e->getMessage());
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('MageDrop_Magento2::releases');
    }
}
