<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Section\Product;

use MageDrop\Magento2\Model\Entity\Section\SectionHandlerInterface;
use MageDrop\Magento2\Model\Entity\Value;
use Magento\Catalog\Model\Product;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Framework\DataObject;

/**
 * Tier prices: [{website_id, cust_group (32000 = all groups), price_qty, price, percentage_value}].
 * Persisted by the Tierprice backend model on product save. Global scope.
 */
class TierPrice implements SectionHandlerInterface
{
    public const FIELD = 'tier_price';

    public function extract(array $post, DataObject $entity, int $storeId): array
    {
        $raw = $post['_post']['product'] ?? [];
        if (!array_key_exists(self::FIELD, $raw)) {
            return [];
        }

        return [self::FIELD => Value::json($this->normalise((array) ($post[self::FIELD] ?? [])))];
    }

    public function current(DataObject $entity, int $storeId, ?array $fields = null): array
    {
        if (($fields !== null && !in_array(self::FIELD, $fields, true)) || !$entity instanceof Product) {
            return [];
        }
        $rows = $entity->getData(self::FIELD);
        if ($rows === null) {
            $rows = $entity->getResource()->getAttribute(self::FIELD)?->getBackend()?->afterLoad($entity) ? $entity->getData(self::FIELD) : [];
        }

        return [self::FIELD => Value::json($this->normalise((array) $rows))];
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
            throw new \InvalidArgumentException('Tier prices cannot inherit.');
        }
        $rows = [];
        foreach ($this->normalise((array) $values[self::FIELD]->value) as $row) {
            $rows[] = [
                'website_id' => $row['website_id'],
                'cust_group' => $row['cust_group'],
                'all_groups' => $row['cust_group'] === (int) GroupInterface::CUST_GROUP_ALL ? 1 : 0,
                'price_qty' => $row['price_qty'],
                'price' => $row['price'],
                'percentage_value' => $row['percentage_value'],
                'value_type' => $row['percentage_value'] !== null ? 'percent' : 'fixed',
            ];
        }
        $entity->setData(self::FIELD, $rows);
    }

    public function overlay(DataObject $entity, array $values): void
    {
        if (isset($values[self::FIELD]) && !$values[self::FIELD]->isInherit()) {
            $entity->setData(self::FIELD, $this->normalise((array) $values[self::FIELD]->value));
        }
    }

    public function toFormData(array $data, array $values): array
    {
        if (isset($values[self::FIELD]) && !$values[self::FIELD]->isInherit()) {
            $rows = [];
            $i = 1;
            foreach ($this->normalise((array) $values[self::FIELD]->value) as $row) {
                $rows[] = [
                    'record_id' => $i++,
                    'website_id' => (string) $row['website_id'],
                    'cust_group' => (string) $row['cust_group'],
                    'price_qty' => (string) $row['price_qty'],
                    'price' => $row['price'] !== null ? (string) $row['price'] : '',
                    'percentage_value' => $row['percentage_value'] !== null ? (string) $row['percentage_value'] : '',
                    'value_type' => $row['percentage_value'] !== null ? 'percent' : 'fixed',
                ];
            }
            $data[self::FIELD] = $rows;
        }

        return $data;
    }

    private function normalise(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !empty($row['delete'])) {
                continue;
            }
            $allGroups = !empty($row['all_groups']) || (isset($row['cust_group']) && (int) $row['cust_group'] === (int) GroupInterface::CUST_GROUP_ALL);
            $percentage = isset($row['percentage_value']) && $row['percentage_value'] !== '' && $row['percentage_value'] !== null
                ? round((float) $row['percentage_value'], 4) : null;
            if (($row['value_type'] ?? null) === 'fixed') {
                $percentage = null;
            }
            $out[] = [
                'website_id' => (int) ($row['website_id'] ?? 0),
                'cust_group' => $allGroups ? (int) GroupInterface::CUST_GROUP_ALL : (int) ($row['cust_group'] ?? 0),
                'price_qty' => round((float) ($row['price_qty'] ?? 1), 4),
                'price' => $percentage === null && isset($row['price']) && $row['price'] !== '' ? round((float) $row['price'], 4) : null,
                'percentage_value' => $percentage,
            ];
        }
        usort($out, fn ($a, $b) => [$a['website_id'], $a['cust_group'], $a['price_qty']] <=> [$b['website_id'], $b['cust_group'], $b['price_qty']]);

        return $out;
    }
}
