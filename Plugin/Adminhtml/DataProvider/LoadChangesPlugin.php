<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Adminhtml\DataProvider;

use MageDrop\Magento2\Model\Entity\AdapterPool;
use MageDrop\Magento2\Model\Entity\Value;
use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\Store;
use Psr\Log\LoggerInterface;

/**
 * "Load from Release": when the edit form is opened with ?magedrop_load=<release>,
 * merge that release's staged values for this entity into the data provider
 * output so the admin sees (and can save) the staged version.
 */
class LoadChangesPlugin
{
    public function __construct(
        private ApiClient $apiClient,
        private AdapterPool $adapterPool,
        private RequestInterface $request,
        private LoggerInterface $logger,
        private string $entityType = ''
    ) {
    }

    public function afterGetData($subject, $result)
    {
        if (!is_array($result) || empty($result)) {
            return $result;
        }

        $releaseId = (int) $this->request->getParam('magedrop_load', 0);

        if (!$releaseId || !$this->apiClient->isEnabled()) {
            return $result;
        }

        try {
            $adapter = $this->adapterPool->get($this->entityType);
            $storeId = $adapter->resolveStoreId($this->request);
        } catch (\Throwable $e) {
            $this->logger->error('MageDrop load changes plugin error: ' . $e->getMessage());

            return $result;
        }

        foreach ($result as $key => &$data) {
            if (!is_array($data)) {
                continue;
            }

            $remoteId = (string) ($data[$adapter->getFormIdKey()] ?? $data[$adapter->getIdParam()] ?? '');
            if ($remoteId === '' && is_numeric($key)) {
                $remoteId = (string) $key;
            }
            if ($remoteId === '') {
                continue;
            }

            try {
                $groups = $this->apiClient->getPreviewChanges($releaseId, $this->entityType, $remoteId);
                $values = $this->selectValues($groups, $storeId);

                if (!empty($values)) {
                    $data = $adapter->toFormData($data, $values);
                    $data['magedrop_loaded_release'] = $releaseId;
                    $data['magedrop_loaded_count'] = count($values);
                }
            } catch (\Throwable $e) {
                $this->logger->error('MageDrop load changes plugin error: ' . $e->getMessage());
            }
        }
        unset($data);

        return $result;
    }

    /**
     * Default-scope changes first, then the requested store's own changes on top.
     *
     * @return array<string, Value>
     */
    private function selectValues(array $groups, int $storeId): array
    {
        $default = [];
        $specific = [];
        foreach ($groups as $group) {
            $scope = (int) ($group['scope_store_id'] ?? Store::DEFAULT_STORE_ID);
            $changes = [];
            foreach ($group['changes'] as $field => $raw) {
                $changes[$field] = is_array($raw) ? Value::fromArray($raw) : Value::text($raw);
            }
            if ($scope === Store::DEFAULT_STORE_ID) {
                $default += $changes;
            } elseif ($scope === $storeId) {
                $specific += $changes;
            }
        }

        return $specific + $default;
    }
}
