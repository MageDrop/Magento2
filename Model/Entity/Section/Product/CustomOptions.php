<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Section\Product;

use MageDrop\Magento2\Model\Entity\Section\SectionHandlerInterface;
use MageDrop\Magento2\Model\Entity\Value;
use Magento\Catalog\Api\Data\ProductCustomOptionInterfaceFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Option;
use Magento\Catalog\Model\Product\Option\Value as OptionValue;
use Magento\Catalog\Model\Product\Option\ValueFactory as OptionValueFactory;
use Magento\Catalog\Model\Product\OptionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Store\Model\Store;

/**
 * Customizable options as one normalised list:
 *   [{option_id, type, title, is_require, sort_order, price, price_type, sku,
 *     max_characters, file_extension, image_size_x, image_size_y,
 *     values: [{option_type_id, title, price, price_type, sku, sort_order}]}]
 *
 * Stage time reads the Option models Initialization\Helper::fillProductOptions
 * built from the POST; apply rebuilds them the same way and lets
 * Option\SaveHandler persist (it deletes options missing from the list;
 * removed values are marked is_delete here).
 */
class CustomOptions implements SectionHandlerInterface
{
    public const FIELD = 'options';

    private const OPTION_KEYS = ['type', 'title', 'is_require', 'sort_order', 'price', 'price_type', 'sku', 'max_characters', 'file_extension', 'image_size_x', 'image_size_y'];
    private const VALUE_KEYS = ['title', 'price', 'price_type', 'sku', 'sort_order'];
    private const SELECT_TYPES = ['drop_down', 'radio', 'checkbox', 'multiple'];

    public function __construct(
        private ProductCustomOptionInterfaceFactory $customOptionFactory,
        private OptionFactory $optionFactory,
        private OptionValueFactory $optionValueFactory,
        private ResourceConnection $resourceConnection
    ) {
    }

    public function extract(array $post, DataObject $entity, int $storeId): array
    {
        $form = $post['_form_product'] ?? null;
        $raw = $post['_post']['product'] ?? [];
        if (!$form instanceof Product || empty($raw['affect_product_custom_options']) || $form->getOptionsReadonly()) {
            return [];
        }

        return [self::FIELD => Value::json($this->normaliseOptions((array) $form->getOptions()))];
    }

    public function current(DataObject $entity, int $storeId, ?array $fields = null): array
    {
        if (($fields !== null && !in_array(self::FIELD, $fields, true)) || !$entity instanceof Product) {
            return [];
        }

        return [self::FIELD => Value::json($this->normaliseOptions((array) $entity->getOptions()))];
    }

    public function handles(string $field, DataObject $entity): bool
    {
        return $field === self::FIELD;
    }

    public function isScopable(DataObject $entity, string $field): bool
    {
        return true;
    }

    public function isOverridden(DataObject $entity, string $field, int $storeId): bool
    {
        if ($storeId === Store::DEFAULT_STORE_ID || !$entity->getId()) {
            return true;
        }
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['t' => $this->resourceConnection->getTableName('catalog_product_option_title')], [new \Zend_Db_Expr('COUNT(*)')])
            ->join(['o' => $this->resourceConnection->getTableName('catalog_product_option')], 'o.option_id = t.option_id', [])
            ->where('o.product_id = ?', (int) $entity->getId())
            ->where('t.store_id = ?', $storeId);

        return (int) $connection->fetchOne($select) > 0;
    }

    public function apply(DataObject $entity, array $values, int $storeId): void
    {
        if (!isset($values[self::FIELD]) || !$entity instanceof Product) {
            return;
        }
        $value = $values[self::FIELD];
        if ($value->isInherit()) {
            throw new \InvalidArgumentException('Custom options cannot inherit; stage the default-scope options instead.');
        }

        $staged = is_array($value->value) ? $value->value : [];
        $current = $this->normaliseOptions((array) $entity->getOptions());
        $currentById = [];
        foreach ($current as $option) {
            if ($option['option_id']) {
                $currentById[$option['option_id']] = $option;
            }
        }

        $options = [];
        foreach ($staged as $option) {
            if (!is_array($option) || empty($option['title'])) {
                continue;
            }
            $optionId = !empty($option['option_id']) && isset($currentById[(int) $option['option_id']]) ? (int) $option['option_id'] : null;

            $data = ['option_id' => $optionId];
            foreach (self::OPTION_KEYS as $key) {
                if (array_key_exists($key, $option)) {
                    $data[$key] = $option[$key];
                }
            }

            $valuesData = [];
            $keptValueIds = [];
            foreach ((array) ($option['values'] ?? []) as $v) {
                if (!is_array($v)) {
                    continue;
                }
                $row = [];
                foreach (self::VALUE_KEYS as $key) {
                    if (array_key_exists($key, $v)) {
                        $row[$key] = $v[$key];
                    }
                }
                if (!empty($v['option_type_id'])) {
                    $row['option_type_id'] = (int) $v['option_type_id'];
                    $keptValueIds[] = (int) $v['option_type_id'];
                }
                $valuesData[] = $row;
            }
            // Values that existed before but are not staged any more must be deleted explicitly
            if ($optionId) {
                foreach ($currentById[$optionId]['values'] as $existing) {
                    if ($existing['option_type_id'] && !in_array((int) $existing['option_type_id'], $keptValueIds, true)) {
                        $valuesData[] = ['option_type_id' => (int) $existing['option_type_id'], 'is_delete' => 1];
                    }
                }
            }
            if ($valuesData) {
                $data['values'] = $valuesData;
            }

            $model = $this->customOptionFactory->create(['data' => $data]);
            $model->setProductSku($entity->getSku());
            $options[] = $model;
        }

        $entity->setOptions($options);
        $entity->setCanSaveCustomOptions(true);
    }

    public function overlay(DataObject $entity, array $values): void
    {
        if (!isset($values[self::FIELD]) || $values[self::FIELD]->isInherit() || !$entity instanceof Product) {
            return;
        }
        $staged = is_array($values[self::FIELD]->value) ? $values[self::FIELD]->value : [];

        $options = [];
        $required = false;
        $synthetic = -1;
        foreach ($staged as $index => $option) {
            if (!is_array($option)) {
                continue;
            }
            $data = ['option_id' => !empty($option['option_id']) ? (int) $option['option_id'] : $synthetic--, 'product_id' => $entity->getId()];
            foreach (self::OPTION_KEYS as $key) {
                if (array_key_exists($key, $option)) {
                    $data[$key] = $option[$key];
                }
            }
            /** @var Option $model */
            $model = $this->optionFactory->create();
            $model->setData($data);
            $model->setProduct($entity);
            foreach ((array) ($option['values'] ?? []) as $v) {
                if (!is_array($v)) {
                    continue;
                }
                /** @var OptionValue $valueModel */
                $valueModel = $this->optionValueFactory->create();
                $valueModel->setData(['option_type_id' => !empty($v['option_type_id']) ? (int) $v['option_type_id'] : $synthetic--] + $v);
                $valueModel->setOption($model);
                $model->addValue($valueModel);
            }
            if (!empty($option['is_require'])) {
                $required = true;
            }
            $options[$model->getId()] = $model;
        }

        $entity->setData(self::FIELD, $options);
        $entity->setHasOptions($options ? 1 : 0);
        $entity->setRequiredOptions($required ? 1 : 0);
    }

    public function toFormData(array $data, array $values): array
    {
        if (!isset($values[self::FIELD]) || $values[self::FIELD]->isInherit()) {
            return $data;
        }
        $rows = [];
        $i = 1;
        foreach ((array) $values[self::FIELD]->value as $option) {
            if (!is_array($option)) {
                continue;
            }
            $row = ['record_id' => $i, 'option_id' => $option['option_id'] ?? null, 'is_use_default' => false];
            foreach (self::OPTION_KEYS as $key) {
                if (array_key_exists($key, $option)) {
                    $row[$key] = $option[$key];
                }
            }
            $row['values'] = [];
            $j = 1;
            foreach ((array) ($option['values'] ?? []) as $v) {
                if (is_array($v)) {
                    $row['values'][] = ['record_id' => $j++, 'option_type_id' => $v['option_type_id'] ?? null] + $v;
                }
            }
            $rows[] = $row;
            $i++;
        }
        $data[self::FIELD] = $rows;
        $data['affect_product_custom_options'] = 1;

        return $data;
    }

    /**
     * @param Option[]|array[] $options
     */
    private function normaliseOptions(array $options): array
    {
        $out = [];
        foreach ($options as $option) {
            $raw = $option instanceof DataObject ? $option->getData() : (array) $option;
            $entry = ['option_id' => !empty($raw['option_id']) ? (int) $raw['option_id'] : null];
            foreach (self::OPTION_KEYS as $key) {
                $entry[$key] = $this->scalar($raw[$key] ?? null, $key);
            }
            // Select types (drop-down, radio, checkbox, multiple) are priced per value; the option's
            // own price is unused, and the store-view form posts 0/fixed for it where none is saved
            if (in_array($entry['type'], self::SELECT_TYPES, true)) {
                $entry['price'] = null;
                $entry['price_type'] = '';
            }
            $values = [];
            $rawValues = $option instanceof Option ? ($option->getValues() ?: $option->getData('values')) : ($raw['values'] ?? null);
            foreach ((array) $rawValues as $v) {
                $vraw = $v instanceof DataObject ? $v->getData() : (array) $v;
                if (!empty($vraw['is_delete'])) {
                    continue;
                }
                $ventry = ['option_type_id' => !empty($vraw['option_type_id']) ? (int) $vraw['option_type_id'] : null];
                foreach (self::VALUE_KEYS as $key) {
                    $ventry[$key] = $this->scalar($vraw[$key] ?? null, $key);
                }
                $values[] = $ventry;
            }
            usort($values, fn ($a, $b) => [(int) $a['sort_order'], (string) $a['title']] <=> [(int) $b['sort_order'], (string) $b['title']]);
            $entry['values'] = $values;
            $out[] = $entry;
        }
        usort($out, fn ($a, $b) => [(int) $a['sort_order'], (string) $a['title']] <=> [(int) $b['sort_order'], (string) $b['title']]);

        return $out;
    }

    private function scalar(mixed $value, string $key): mixed
    {
        if ($value === null || $value === '') {
            return in_array($key, ['price', 'max_characters', 'image_size_x', 'image_size_y'], true) ? null : ($key === 'is_require' || $key === 'sort_order' ? 0 : '');
        }
        if (in_array($key, ['is_require', 'sort_order', 'max_characters', 'image_size_x', 'image_size_y'], true)) {
            return (int) $value;
        }
        if ($key === 'price') {
            return round((float) $value, 4);
        }

        return (string) $value;
    }
}
