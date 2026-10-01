<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Section\Product;

use MageDrop\Magento2\Model\Entity\Section\SectionHandlerInterface;
use MageDrop\Magento2\Model\Entity\Value;
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;

/**
 * Category and website assignments of a product (global scope).
 */
class Assignments implements SectionHandlerInterface
{
    public const FIELDS = ['category_ids', 'website_ids'];

    public function extract(array $post, DataObject $entity, int $storeId): array
    {
        $values = [];
        // The initialised model always carries these; only stage what the form actually posted
        $posted = (array) ($post['_post']['product'] ?? $post);
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $post) || !array_key_exists($field, $posted)) {
                continue;
            }
            $raw = $post[$field];
            if (is_string($raw)) {
                $raw = $raw === '' ? [] : explode(',', $raw);
            }
            if (!is_array($raw)) {
                continue;
            }
            $values[$field] = Value::json($this->normalise($raw));
        }

        return $values;
    }

    public function current(DataObject $entity, int $storeId, ?array $fields = null): array
    {
        if (!$entity instanceof Product) {
            return [];
        }
        $values = [];
        foreach (self::FIELDS as $field) {
            if ($fields !== null && !in_array($field, $fields, true)) {
                continue;
            }
            $ids = $field === 'category_ids' ? $entity->getCategoryIds() : $entity->getWebsiteIds();
            $values[$field] = Value::json($this->normalise((array) $ids));
        }

        return $values;
    }

    public function handles(string $field, DataObject $entity): bool
    {
        return in_array($field, self::FIELDS, true);
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
        if (!$entity instanceof Product) {
            return;
        }
        foreach ($values as $field => $value) {
            if ($value->isInherit()) {
                throw new \InvalidArgumentException(sprintf('%s cannot inherit.', $field));
            }
            $ids = $this->normalise(is_array($value->value) ? $value->value : []);
            if ($field === 'category_ids') {
                // Category\Link\SaveHandler merges the loaded category_links extension
                // attribute with the model ids, which would silently keep removed categories.
                $extension = $entity->getExtensionAttributes();
                if ($extension && method_exists($extension, 'setCategoryLinks')) {
                    $extension->setCategoryLinks(null);
                    $entity->setExtensionAttributes($extension);
                }
                $entity->setCategoryIds($ids);
            } else {
                $entity->setWebsiteIds($ids);
            }
        }
    }

    public function overlay(DataObject $entity, array $values): void
    {
        // Listing membership is index driven; nothing meaningful to overlay in-memory.
    }

    public function toFormData(array $data, array $values): array
    {
        foreach ($values as $field => $value) {
            if (!$value->isInherit()) {
                $data[$field] = array_map('strval', $this->normalise((array) $value->value));
            }
        }

        return $data;
    }

    /**
     * @return int[]
     */
    private function normalise(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }
        sort($out, SORT_NUMERIC);

        return array_values($out);
    }
}
