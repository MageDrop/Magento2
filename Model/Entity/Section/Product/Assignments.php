<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Section\Product;

use MageDrop\Magento2\Model\Entity\Section\AfterSaveInterface;
use MageDrop\Magento2\Model\Entity\Section\CapturesPreviousInterface;
use MageDrop\Magento2\Model\Entity\Section\SectionHandlerInterface;
use MageDrop\Magento2\Model\Entity\Value;
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;

/**
 * Category and website assignments of a product (global scope).
 *
 * category_ids is staged as a list of ids. Removing a category from the product drops
 * the product's position in that category, so the previous value captured for a
 * rollback is a list of {category_id, position} records and applying it restores the
 * positions too.
 */
class Assignments implements SectionHandlerInterface, CapturesPreviousInterface, AfterSaveInterface
{
    public const FIELDS = ['category_ids', 'website_ids'];

    /** @var array<int, array<int, int>> product id => category id => position to restore */
    private array $positions = [];

    public function previous(DataObject $entity, string $field, int $storeId): ?Value
    {
        if ($field !== 'category_ids' || !$entity instanceof Product || !$entity->getId()) {
            return null;
        }
        $records = [];
        foreach ($this->categoryPositions($entity) as $categoryId => $position) {
            $records[] = ['category_id' => $categoryId, 'position' => $position];
        }

        return Value::json($records);
    }

    public function hasStoreViewState(string $field): bool
    {
        return false; // assignments are global
    }

    public function afterSave(DataObject $entity, int $storeId): void
    {
        $id = (int) $entity->getId();
        $positions = $this->positions[$id] ?? [];
        unset($this->positions[$id]);
        if (!$positions || !$entity instanceof Product) {
            return;
        }
        $connection = $entity->getResource()->getConnection();
        $table = $entity->getResource()->getTable('catalog_category_product');
        foreach ($positions as $categoryId => $position) {
            $connection->update($table, ['position' => $position], ['product_id = ?' => $id, 'category_id = ?' => $categoryId]);
        }
    }

    /**
     * @return array<int, int> category id => position of this product in it
     */
    private function categoryPositions(Product $product): array
    {
        $connection = $product->getResource()->getConnection();

        return array_map('intval', $connection->fetchPairs(
            $connection->select()
                ->from($product->getResource()->getTable('catalog_category_product'), ['category_id', 'position'])
                ->where('product_id = ?', (int) $product->getId())
                ->order('category_id')
        ));
    }

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
            $raw = is_array($value->value) ? $value->value : [];
            $ids = $this->normalise($raw);
            if ($field === 'category_ids') {
                // A captured previous value (rollback) also carries the positions
                $positions = [];
                foreach ($raw as $record) {
                    if (is_array($record) && isset($record['category_id'], $record['position'])) {
                        $positions[(int) $record['category_id']] = (int) $record['position'];
                    }
                }
                if ($positions) {
                    $this->positions[(int) $entity->getId()] = $positions;
                }
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
            $id = (int) (is_array($id) ? ($id['category_id'] ?? 0) : $id);
            if ($id > 0) {
                $out[$id] = $id;
            }
        }
        sort($out, SORT_NUMERIC);

        return array_values($out);
    }
}
