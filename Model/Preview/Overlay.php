<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Preview;

use MageDrop\Magento2\Model\Entity\AdapterPool;
use MageDrop\Magento2\Model\Entity\Value;
use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Framework\DataObject;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies a release's staged changes to an entity in-memory for preview.
 *
 * All of a release's staged changes are fetched in a single SaaS request on
 * first use and held for the rest of the request. Changes are grouped by store
 * scope: default-scope changes apply everywhere the field is not overridden by
 * the current store view; store-specific changes apply only on that store view
 * and win over default ones.
 */
class Overlay
{
    /** @var array<string, array<int, array{scope_store_id: int|null, changes: array}>>|null */
    private ?array $groupMap = null;

    /** Collection items already overlaid this request */
    private \WeakMap $overlaidItems;

    /** Collections currently being iterated by applyToCollection() */
    private \WeakMap $iterating;

    public function __construct(
        private State $state,
        private ApiClient $apiClient,
        private AdapterPool $adapterPool,
        private StoreManagerInterface $storeManager,
        private LoggerInterface $logger
    ) {
        $this->overlaidItems = new \WeakMap();
        $this->iterating = new \WeakMap();
    }

    /**
     * For afterLoad plugins on collections. load() runs its after-plugins on every call,
     * including the early return once loaded, and getItems() calls load() — so guard
     * re-entry and overlay each item only once.
     */
    public function applyToCollection(\Magento\Framework\Data\Collection $collection, string $entityType): void
    {
        if (!$this->state->isActive() || isset($this->iterating[$collection])) {
            return;
        }

        $this->iterating[$collection] = true;
        try {
            foreach ($collection->getItems() as $item) {
                if (isset($this->overlaidItems[$item]) || !$item->getId()) {
                    continue;
                }
                $this->overlaidItems[$item] = true;
                $this->applyTo($item, $entityType);
            }
        } finally {
            unset($this->iterating[$collection]);
        }
    }

    public function applyTo(DataObject $entity, string $entityType): bool
    {
        if (!$this->state->isActive()) {
            return false;
        }

        $entityId = (int) $entity->getId();
        if (!$entityId) {
            return false;
        }

        $groups = $this->getGroupMap()[$entityType . ':' . $entityId] ?? [];
        if (!$groups) {
            return false;
        }

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable) {
            $storeId = Store::DEFAULT_STORE_ID;
        }

        $adapter = $this->adapterPool->has($entityType) ? $this->adapterPool->get($entityType) : null;

        $default = [];
        $specific = [];
        foreach ($groups as $group) {
            $scope = (int) ($group['scope_store_id'] ?? Store::DEFAULT_STORE_ID);
            $changes = $this->toValues($group['changes']);
            if ($scope === Store::DEFAULT_STORE_ID) {
                $default = $changes + $default;
            } elseif ($scope === $storeId) {
                $specific = $changes + $specific;
            }
        }

        // A default-scope change must not show through where this store view overrides the field
        if ($default && $adapter && $adapter->supportsStoreScope() && $storeId !== Store::DEFAULT_STORE_ID) {
            foreach (array_keys($default) as $field) {
                if (isset($specific[$field])) {
                    continue;
                }
                try {
                    // Non-scopable fields (gallery, options, third-party sections) have no
                    // store override to respect, though their sections report "overridden"
                    if ($adapter->isScopable($entity, $field) && $adapter->isOverridden($entity, $field, $storeId)) {
                        unset($default[$field]);
                    }
                } catch (\Throwable $e) {
                    $this->logger->debug('MageDrop overlay override check failed: ' . $e->getMessage());
                }
            }
        }

        $values = $specific + $default;
        foreach ($values as $field => $value) {
            if ($value->isInherit()) {
                unset($values[$field]);
            }
        }

        if (!$values) {
            return false;
        }

        try {
            if ($adapter) {
                $adapter->overlay($entity, $values);
            } else {
                foreach ($values as $field => $value) {
                    $entity->setData($field, $value->forModel());
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('MageDrop preview overlay error: ' . $e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * @return array<string, Value>
     */
    private function toValues(array $changes): array
    {
        $values = [];
        foreach ($changes as $field => $raw) {
            $values[$field] = is_array($raw) ? Value::fromArray($raw) : Value::text($raw);
        }

        return $values;
    }

    /**
     * Load (once per request) the full set of staged changes for the active release.
     */
    private function getGroupMap(): array
    {
        if ($this->groupMap !== null) {
            return $this->groupMap;
        }

        try {
            $this->groupMap = $this->apiClient->getAllPreviewChanges(
                (int) $this->state->getReleaseId()
            );
        } catch (\Throwable $e) {
            $this->logger->error('MageDrop preview overlay error: ' . $e->getMessage());
            $this->groupMap = [];
        }

        return $this->groupMap;
    }
}
