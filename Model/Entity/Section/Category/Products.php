<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Section\Category;

use MageDrop\Magento2\Model\Entity\Section\SectionHandlerInterface;
use MageDrop\Magento2\Model\Entity\Value;
use Magento\Catalog\Model\Category;
use Magento\Framework\DataObject;

/**
 * "Products in Category" assignments — the category_products JSON the admin
 * grid posts, applied through Category::setPostedProducts() so the resource
 * model rewrites catalog_category_product on save. Global scope.
 */
class Products implements SectionHandlerInterface
{
    public const FIELD = 'category_products';

    public function extract(array $post, DataObject $entity, int $storeId): array
    {
        if (!array_key_exists(self::FIELD, $post)) {
            return [];
        }
        if ($entity instanceof Category && $entity->getProductsReadonly()) {
            return [];
        }

        $raw = $post[self::FIELD];
        if (is_string($raw)) {
            $decoded = $raw === '' ? [] : json_decode($raw, true);
            if (!is_array($decoded)) {
                return [];
            }
        } elseif (is_array($raw)) {
            $decoded = $raw;
        } else {
            return [];
        }

        return [self::FIELD => Value::json($this->normalise($decoded))];
    }

    public function current(DataObject $entity, int $storeId, ?array $fields = null): array
    {
        if ($fields !== null && !in_array(self::FIELD, $fields, true)) {
            return [];
        }
        if (!$entity instanceof Category || !$entity->getId()) {
            return [];
        }

        return [self::FIELD => Value::json($this->normalise($entity->getProductsPosition()))];
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
        if (!isset($values[self::FIELD]) || !$entity instanceof Category) {
            return;
        }
        $value = $values[self::FIELD];
        if ($value->isInherit()) {
            throw new \InvalidArgumentException('Category product assignments cannot inherit.');
        }
        $entity->setPostedProducts($this->normalise(is_array($value->value) ? $value->value : []));
    }

    public function overlay(DataObject $entity, array $values): void
    {
        // Listing membership comes from the search index; nothing to overlay in-memory.
    }

    public function toFormData(array $data, array $values): array
    {
        if (isset($values[self::FIELD]) && !$values[self::FIELD]->isInherit()) {
            $data[self::FIELD] = json_encode((object) $this->normalise((array) $values[self::FIELD]->value));
        }

        return $data;
    }

    /**
     * @return array<int, int> productId => position
     */
    private function normalise(array $positions): array
    {
        $out = [];
        foreach ($positions as $productId => $position) {
            $productId = (int) $productId;
            if ($productId <= 0) {
                continue;
            }
            $out[$productId] = (int) $position;
        }
        ksort($out, SORT_NUMERIC);

        return $out;
    }
}
