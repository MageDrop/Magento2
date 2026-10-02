<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model;

use MageDrop\Magento2\Api\Data\ApplyResultInterface;
use MageDrop\Magento2\Api\Data\ChangeInterface;
use MageDrop\Magento2\Api\Data\EntityStateInterface;
use MageDrop\Magento2\Api\EntityManagementInterface;
use MageDrop\Magento2\Model\Api\Data\ApplyResult;
use MageDrop\Magento2\Model\Api\Data\Change;
use MageDrop\Magento2\Model\Api\Data\EntityState;
use MageDrop\Magento2\Model\Entity\Section\CapturesPreviousInterface;
use MageDrop\Magento2\Model\Entity\AdapterPool;
use MageDrop\Magento2\Model\Entity\Value;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class EntityManagement implements EntityManagementInterface
{
    public function __construct(
        private AdapterPool $adapterPool,
        private StoreManagerInterface $storeManager,
        private ApplyContext $applyContext,
        private LoggerInterface $logger
    ) {
    }

    public function getState(string $type, string $id, int $storeId = 0): EntityStateInterface
    {
        $adapter = $this->adapterPool->get($type);
        $storeId = $this->normaliseStoreId($adapter->supportsStoreScope(), $storeId);
        $entity = $this->loadOrFail($adapter, $id, $storeId);

        $fields = [];
        $overridden = [];
        $scopable = [];
        foreach ($adapter->current($entity, $storeId) as $field => $value) {
            $fields[] = Change::fromValue($field, $value);
            if ($storeId === Store::DEFAULT_STORE_ID || !$this->isStoreScoped($adapter, $entity, $field)) {
                continue;
            }
            $scopable[] = $field;
            if ($adapter->isOverridden($entity, $field, $storeId)) {
                $overridden[] = $field;
            }
        }

        return new EntityState($type, $id, $storeId, $adapter->getTitle($entity), $fields, $overridden, $scopable);
    }

    public function apply(string $type, string $id, int $storeId, array $changes): ApplyResultInterface
    {
        $adapter = $this->adapterPool->get($type);
        $storeId = $this->normaliseStoreId($adapter->supportsStoreScope(), $storeId);
        $entity = $this->loadOrFail($adapter, $id, $storeId);

        $values = [];
        foreach ($changes as $change) {
            if (!$change instanceof ChangeInterface) {
                throw new LocalizedException(__('Invalid change payload.'));
            }
            $value = $change instanceof Change ? $change->toValue() : $this->toValue($change);
            if ($value->isInherit() && $storeId === Store::DEFAULT_STORE_ID) {
                throw new LocalizedException(
                    __('Field "%1" cannot use the default value at the default scope.', $change->getField())
                );
            }
            $values[$change->getField()] = $value;
        }

        if (!$values) {
            return new ApplyResult($adapter->getTitle($entity), [], []);
        }

        // Capture previous state before mutating, including "inherited" markers so a
        // rollback re-creates exactly the override situation that existed before.
        $current = $adapter->current($entity, $storeId, array_keys($values));
        $previous = [];
        foreach (array_keys($values) as $field) {
            $own = method_exists($adapter, 'previous') ? $adapter->previous($entity, $field, $storeId) : null;
            if ($own !== null) {
                $previous[] = Change::fromValue($field, $own);
                continue;
            }
            if ($storeId !== Store::DEFAULT_STORE_ID && !$adapter->isOverridden($entity, $field, $storeId)) {
                $previous[] = Change::fromValue($field, Value::inherit());
                continue;
            }
            $previous[] = Change::fromValue($field, $current[$field] ?? Value::text(''));
        }

        $this->applyContext->begin('magedrop_deploy');
        try {
            $adapter->apply($entity, $values, $storeId);
            $adapter->save($entity, $storeId, array_keys($values));
        } catch (LocalizedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('MageDrop apply failed', [
                'type' => $type,
                'id' => $id,
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);
            throw new LocalizedException(__('Apply failed: %1', $e->getMessage()), $e);
        } finally {
            $this->applyContext->end();
        }

        return new ApplyResult($adapter->getTitle($entity), $previous, array_keys($values));
    }

    /**
     * Has a store-view value: scopable attributes, plus sections whose store-view state
     * is their own (the gallery: per-image store rows and roles) even though the field
     * itself is not a scopable attribute.
     */
    private function isStoreScoped($adapter, $entity, string $field): bool
    {
        if ($adapter->isScopable($entity, $field)) {
            return true;
        }
        $sections = method_exists($adapter, 'getSections') ? $adapter->getSections() : [];
        foreach ($sections as $section) {
            if ($section instanceof CapturesPreviousInterface && $section->handles($field, $entity) && $section->hasStoreViewState($field)) {
                return true;
            }
        }

        return false;
    }

    public function capabilities(): array
    {
        return [
            'version' => Version::VERSION,
            'features' => implode(',', Version::CAPABILITIES),
            'entity_types' => implode(',', array_keys($this->adapterPool->all())),
        ];
    }

    private function loadOrFail($adapter, string $id, int $storeId)
    {
        try {
            return $adapter->load($id, $storeId);
        } catch (NoSuchEntityException $e) {
            throw new NoSuchEntityException(
                __('%1 with id "%2" does not exist.', $adapter->getLabel(), $id)
            );
        }
    }

    private function normaliseStoreId(bool $supportsScope, int $storeId): int
    {
        if (!$supportsScope || $storeId <= 0 || $this->storeManager->hasSingleStore()) {
            return Store::DEFAULT_STORE_ID;
        }
        // Throws NoSuchEntityException for unknown stores
        $this->storeManager->getStore($storeId);

        return $storeId;
    }

    private function toValue(ChangeInterface $change): Value
    {
        return Change::fromValue($change->getField(), Value::text(''))
            ->setType($change->getType())
            ->setValue($change->getValue())
            ->setUrl($change->getUrl())
            ->toValue();
    }
}
