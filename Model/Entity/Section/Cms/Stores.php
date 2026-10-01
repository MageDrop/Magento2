<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Section\Cms;

use MageDrop\Magento2\Model\Entity\Section\SectionHandlerInterface;
use MageDrop\Magento2\Model\Entity\Value;
use Magento\Framework\DataObject;
use Magento\Store\Model\Store;

/**
 * Store-view assignment of a CMS page or block (the "Store View" multiselect):
 * a sorted list of store ids, 0 meaning All Store Views. Persisted by the
 * CMS resource models from the model's store_id data on save.
 */
class Stores implements SectionHandlerInterface
{
    public const FIELD = 'store_id';

    public function extract(array $post, DataObject $entity, int $storeId): array
    {
        if (!array_key_exists(self::FIELD, $post) && !array_key_exists('stores', $post)) {
            return [];
        }
        $raw = $post[self::FIELD] ?? $post['stores'];

        return [self::FIELD => Value::json($this->normalise($raw))];
    }

    public function current(DataObject $entity, int $storeId, ?array $fields = null): array
    {
        if ($fields !== null && !in_array(self::FIELD, $fields, true)) {
            return [];
        }
        $ids = $entity->getData(self::FIELD);
        if ($ids === null && method_exists($entity, 'getStores')) {
            $ids = $entity->getStores();
        }

        return [self::FIELD => Value::json($this->normalise($ids ?? []))];
    }

    public function handles(string $field, DataObject $entity): bool
    {
        return $field === self::FIELD;
    }

    public function isScopable(DataObject $entity, string $field): bool
    {
        return false;
    }

    public function isOverridden(DataObject $entity, string $field, int $storeId): bool
    {
        return true;
    }

    public function apply(DataObject $entity, array $values, int $storeId): void
    {
        if (!isset($values[self::FIELD])) {
            return;
        }
        if ($values[self::FIELD]->isInherit()) {
            throw new \InvalidArgumentException('Store view assignment cannot inherit.');
        }
        $ids = $this->normalise((array) $values[self::FIELD]->value);
        $entity->setData(self::FIELD, $ids ?: [Store::DEFAULT_STORE_ID]);
        $entity->setData('stores', $ids ?: [Store::DEFAULT_STORE_ID]);
    }

    public function overlay(DataObject $entity, array $values): void
    {
        // Store membership is a SQL filter on the frontend; nothing to overlay.
    }

    public function toFormData(array $data, array $values): array
    {
        if (isset($values[self::FIELD]) && !$values[self::FIELD]->isInherit()) {
            $data[self::FIELD] = array_map('strval', $this->normalise((array) $values[self::FIELD]->value));
        }

        return $data;
    }

    /**
     * @return int[]
     */
    private function normalise(mixed $ids): array
    {
        if (is_string($ids)) {
            $ids = $ids === '' ? [] : explode(',', $ids);
        }
        if (!is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            if ($id === '' || $id === null) {
                continue;
            }
            $out[(int) $id] = (int) $id;
        }
        // "All Store Views" (0) makes the specific ones redundant
        if (isset($out[Store::DEFAULT_STORE_ID])) {
            return [Store::DEFAULT_STORE_ID];
        }
        sort($out, SORT_NUMERIC);

        return array_values($out);
    }
}
