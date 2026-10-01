<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Section\Product;

use MageDrop\Magento2\Model\Entity\Section\SectionHandlerInterface;
use MageDrop\Magento2\Model\Entity\Value;
use MageDrop\Magento2\Model\Inventory\Msi;
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;

/**
 * Multi-Source Inventory: the "Assign Sources" grid of the product form as one
 * normalised list [{source_code, quantity, status}]. Persisted through MSI's
 * own SourceItemsProcessor (the same service its admin observer uses), which
 * also unassigns sources missing from the list.
 *
 * Only active when MSI is installed and the store runs in multi-source mode;
 * in single-source mode the Stock section owns quantity via the default source.
 */
class SourceItems implements SectionHandlerInterface
{
    public const FIELD = 'source_items';

    public function __construct(
        private Msi $msi
    ) {
    }

    public function extract(array $post, DataObject $entity, int $storeId): array
    {
        if (!$this->msi->isMultiSource()) {
            return [];
        }
        $sources = $post['_post']['sources'] ?? null;
        if (!is_array($sources) || !array_key_exists('assigned_sources', $sources)) {
            return [];
        }
        $form = $post['_form_product'] ?? $entity;
        if ($form instanceof Product && !$this->msi->isManagedType((string) $form->getTypeId())) {
            return [];
        }

        return [self::FIELD => Value::json($this->normalise(is_array($sources['assigned_sources']) ? $sources['assigned_sources'] : []))];
    }

    public function current(DataObject $entity, int $storeId, ?array $fields = null): array
    {
        if (($fields !== null && !in_array(self::FIELD, $fields, true)) || !$entity instanceof Product) {
            return [];
        }
        if (!$this->msi->isMultiSource() || !$this->msi->isManagedType((string) $entity->getTypeId())) {
            return [];
        }

        return [self::FIELD => Value::json($this->normalise($this->msi->getSourceItems((string) $entity->getSku())))];
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
        if (!$this->msi->isAvailable()) {
            throw new \InvalidArgumentException('Source items were staged but Multi-Source Inventory is not installed on this store.');
        }
        if ($values[self::FIELD]->isInherit()) {
            throw new \InvalidArgumentException('Source items cannot inherit.');
        }
        $rows = $this->normalise((array) $values[self::FIELD]->value);
        $this->msi->saveSourceItems((string) $entity->getSku(), $rows);
    }

    public function overlay(DataObject $entity, array $values): void
    {
        // Salable quantity comes from the stock index; nothing to overlay.
    }

    public function toFormData(array $data, array $values): array
    {
        if (isset($values[self::FIELD]) && !$values[self::FIELD]->isInherit()) {
            $rows = [];
            $i = 1;
            foreach ($this->normalise((array) $values[self::FIELD]->value) as $row) {
                $rows[] = [
                    'record_id' => $i++,
                    'source_code' => $row['source_code'],
                    'quantity' => (string) $row['quantity'],
                    'status' => (string) $row['status'],
                    'source_status' => '1',
                    'notify_stock_qty_use_default' => '1',
                ];
            }
            // Top-level "sources" key; the Product adapter lifts it out of the product sub-array
            $data['sources'] = ['assigned_sources' => $rows];
        }

        return $data;
    }

    /**
     * @return array<int, array{source_code: string, quantity: float, status: int}>
     */
    private function normalise(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['source_code'])) {
                continue;
            }
            $code = (string) $row['source_code'];
            $out[$code] = [
                'source_code' => $code,
                'quantity' => round((float) ($row['quantity'] ?? 0), 4),
                'status' => (int) (bool) ($row['status'] ?? 0),
            ];
        }
        ksort($out, SORT_STRING);

        return array_values($out);
    }
}
