<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Adminhtml\DataProvider;

use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Framework\App\RequestInterface;
use Psr\Log\LoggerInterface;

class LoadChangesPlugin
{
    public function __construct(
        private ApiClient $apiClient,
        private RequestInterface $request,
        private LoggerInterface $logger,
        private string $entityType = '',
        private string $entityIdKey = ''
    ) {
    }

    public function afterGetData($subject, array $result): array
    {
        $releaseId = (int) $this->request->getParam('magedrop_load', 0);

        if (!$releaseId || !$this->apiClient->isEnabled()) {
            return $result;
        }

        foreach ($result as $entityId => &$data) {
            $remoteId = (string) ($data[$this->entityIdKey] ?? '');
            if (!$remoteId) {
                continue;
            }

            try {
                $changes = $this->apiClient->getPreviewChanges($releaseId, $this->entityType, $remoteId);

                if (!empty($changes)) {
                    foreach ($changes as $field => $value) {
                        $data[$field] = $value;
                    }
                    $data['magedrop_loaded_release'] = $releaseId;
                    $data['magedrop_loaded_count'] = count($changes);
                }
            } catch (\Exception $e) {
                $this->logger->error('MageDrop load changes plugin error: ' . $e->getMessage());
            }
        }

        return $result;
    }
}
