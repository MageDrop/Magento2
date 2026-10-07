<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Preview;

use MageDrop\Magento2\Model\Entity\Value;
use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Store\Model\Store;

/**
 * A release's staged values for one entity at one store view, as "Load from Release" (and the
 * Quick Preview reload) puts them on the entity an admin edit page is built from. Fetched once
 * per entity per request.
 */
class ReleaseValues
{
    /** @var array<string, array<string, Value>> */
    private array $values = [];

    /** @var array<string, array<string, bool>> */
    private array $overrides = [];

    public function __construct(
        private ApiClient $apiClient
    ) {
    }

    /**
     * @return array<string, Value> field => value
     */
    public function get(int $releaseId, string $entityType, string $entityId, int $storeId): array
    {
        $key = implode('|', [$releaseId, $entityType, $entityId, $storeId]);
        if (!isset($this->values[$key])) {
            $groups = $this->apiClient->getPreviewChanges($releaseId, $entityType, $entityId);
            $this->values[$key] = $this->select($groups, $storeId);
            $this->overrides[$key] = [];
            foreach ($groups as $group) {
                if ($storeId !== Store::DEFAULT_STORE_ID && (int) ($group['scope_store_id'] ?? 0) === $storeId) {
                    foreach ($group['changes'] as $field => $raw) {
                        $value = is_array($raw) ? Value::fromArray($raw) : Value::text($raw);
                        $this->overrides[$key][$field] = !$value->isInherit();
                    }
                }
            }
        }

        return $this->values[$key];
    }

    /**
     * Fields the release changes at this store view itself (not via the default scope):
     * field => true when it sets the view's own value, false when it removes it ("Use Default
     * Value"). Empty at the default scope.
     *
     * @return array<string, bool>
     */
    public function storeOverrides(int $releaseId, string $entityType, string $entityId, int $storeId): array
    {
        if ($storeId === Store::DEFAULT_STORE_ID) {
            return [];
        }
        $this->get($releaseId, $entityType, $entityId, $storeId);

        return $this->overrides[implode('|', [$releaseId, $entityType, $entityId, $storeId])] ?? [];
    }

    /**
     * Default-scope changes first, then the requested store's own changes on top.
     *
     * @return array<string, Value>
     */
    private function select(array $groups, int $storeId): array
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
