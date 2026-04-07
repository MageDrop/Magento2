<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Controller\Adminhtml\Stage;

use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

class QuickPreview extends Action
{
    public function __construct(
        Context $context,
        private ApiClient $apiClient,
        private JsonFactory $jsonFactory,
        private LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $request = $this->getRequest();

        $entityType = $request->getParam('entity_type', '');
        $entityId = $request->getParam('entity_id', '');
        $formData = $request->getParam('form_data', []);

        if (!$entityType || !$entityId || empty($formData)) {
            return $result->setData(['success' => false, 'message' => 'Missing entity data']);
        }

        try {
            $response = $this->apiClient->quickPreview($entityType, $entityId, $formData);

            if (empty($response) || !empty($response['error'])) {
                return $result->setData([
                    'success' => false,
                    'message' => $response['error'] ?? 'Failed to create preview',
                ]);
            }

            return $result->setData([
                'success' => true,
                'preview_url' => $response['preview_url'],
                'change_count' => $response['change_count'] ?? 0,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Quick preview error: ' . $e->getMessage());
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('MageDrop_Magento2::releases');
    }
}
