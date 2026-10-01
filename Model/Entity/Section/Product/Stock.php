<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Section\Product;

use MageDrop\Magento2\Model\Entity\Section\SectionHandlerInterface;
use MageDrop\Magento2\Model\Entity\Value;
use MageDrop\Magento2\Model\Inventory\Msi;
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;

/**
 * Legacy stock item fields from the "Advanced Inventory" / quantity inputs,
 * persisted by CatalogInventory's SaveInventoryDataObserver from stock_data.
 * With MSI installed this writes the default source through the legacy sync.
 * Global scope; preview does not overlay stock (salability comes from the index).
 */
class Stock implements SectionHandlerInterface
{
    public const FIELD = 'stock_data';

    /** Owned by SourceItems when MSI runs in multi-source mode. */
    private const QUANTITY_KEYS = ['qty', 'is_in_stock'];

    public function __construct(
        private Msi $msi
    ) {
    }

    private const KEYS = [
        'qty', 'is_in_stock', 'manage_stock', 'use_config_manage_stock',
        'min_qty', 'use_config_min_qty', 'min_sale_qty', 'use_config_min_sale_qty',
        'max_sale_qty', 'use_config_max_sale_qty', 'backorders', 'use_config_backorders',
        'notify_stock_qty', 'use_config_notify_stock_qty', 'enable_qty_increments', 'use_config_enable_qty_inc',
        'qty_increments', 'use_config_qty_increments', 'is_qty_decimal', 'is_decimal_divided',
    ];

    public function extract(array $post, DataObject $entity, int $storeId): array
    {
        $raw = $post['_post']['product'] ?? [];
        if (!isset($raw[self::FIELD]) && !isset($raw['quantity_and_stock_status'])) {
            return [];
        }
        $stock = is_array($post[self::FIELD] ?? null) ? $post[self::FIELD] : [];
        $qs = is_array($post['quantity_and_stock_status'] ?? null) ? $post['quantity_and_stock_status'] : [];
        if (isset($qs['qty']) && !isset($stock['qty'])) {
            $stock['qty'] = $qs['qty'];
        }
        if (isset($qs['is_in_stock']) && !isset($stock['is_in_stock'])) {
            $stock['is_in_stock'] = $qs['is_in_stock'];
        }
        if (!$stock) {
            return [];
        }

        // Only compare the keys the form actually posted, on top of the live values
        $current = $this->currentRows($entity);
        $merged = $current;
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $stock)) {
                $merged[$key] = $stock[$key];
            }
        }

        return [self::FIELD => Value::json($this->normalise($merged))];
    }

    public function current(DataObject $entity, int $storeId, ?array $fields = null): array
    {
        if (($fields !== null && !in_array(self::FIELD, $fields, true)) || !$entity instanceof Product) {
            return [];
        }

        return [self::FIELD => Value::json($this->normalise($this->currentRows($entity)))];
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
        if (!isset($values[self::FIELD]) || !$entity instanceof Product) {
            return;
        }
        if ($values[self::FIELD]->isInherit()) {
            throw new \InvalidArgumentException('Stock cannot inherit.');
        }
        $rows = $this->normalise((array) $values[self::FIELD]->value);
        $entity->setStockData($rows);
        $entity->setQuantityAndStockStatus(['qty' => $rows['qty'] ?? null, 'is_in_stock' => $rows['is_in_stock'] ?? null]);

        // MSI single-source mode: the default source must follow the legacy quantity, as the admin save does
        if ((array_key_exists('qty', $rows) || array_key_exists('is_in_stock', $rows)) && $this->msi->isAvailable() && !$this->msi->isMultiSource()) {
            $this->msi->syncDefaultSource(
                (string) $entity->getSku(),
                array_key_exists('qty', $rows) ? (float) $rows['qty'] : null,
                array_key_exists('is_in_stock', $rows) ? (int) $rows['is_in_stock'] : null
            );
        }
    }

    public function overlay(DataObject $entity, array $values): void
    {
    }

    public function toFormData(array $data, array $values): array
    {
        if (isset($values[self::FIELD]) && !$values[self::FIELD]->isInherit()) {
            $rows = $this->normalise((array) $values[self::FIELD]->value);
            $data[self::FIELD] = array_map(fn ($v) => $v === null ? '' : (string) $v, $rows);
            $data['quantity_and_stock_status'] = ['qty' => $rows['qty'] ?? '', 'is_in_stock' => $rows['is_in_stock'] ?? ''];
        }

        return $data;
    }

    private function currentRows(DataObject $entity): array
    {
        $item = method_exists($entity, 'getExtensionAttributes') ? $entity->getExtensionAttributes()?->getStockItem() : null;
        if (!$item) {
            return [];
        }
        $rows = [];
        foreach (self::KEYS as $key) {
            $getter = 'get' . str_replace('_', '', ucwords($key, '_'));
            if (method_exists($item, $getter)) {
                $rows[$key] = $item->{$getter}();
            } elseif ($item instanceof DataObject) {
                $rows[$key] = $item->getData($key);
            }
        }

        return $rows;
    }

    private function normalise(array $rows): array
    {
        $out = [];
        $multiSource = $this->msi->isMultiSource();
        foreach (self::KEYS as $key) {
            if ($multiSource && in_array($key, self::QUANTITY_KEYS, true)) {
                continue; // quantity per source lives in source_items
            }
            if (!array_key_exists($key, $rows) || $rows[$key] === null || $rows[$key] === '') {
                continue;
            }
            $value = $rows[$key];
            $out[$key] = in_array($key, ['qty', 'min_qty', 'min_sale_qty', 'max_sale_qty', 'notify_stock_qty', 'qty_increments'], true)
                ? round((float) $value, 4)
                : (int) (is_bool($value) ? $value : (float) $value);
        }
        ksort($out);

        return $out;
    }
}
