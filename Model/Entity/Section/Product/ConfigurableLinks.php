<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Section\Product;

use MageDrop\Magento2\Model\Entity\Section\SectionHandlerInterface;
use MageDrop\Magento2\Model\Entity\Value;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\DataObject;

/**
 * Which simple products a configurable is made of, for configurable products only:
 *   {attributes: [attribute codes], products: [child ids]}
 *
 * Only the association is staged. Edits to the child products inside the
 * variations matrix belong to those products (stage them individually), and
 * brand-new variations cannot be staged because Magento would have to create
 * the child products first.
 */
class ConfigurableLinks implements SectionHandlerInterface
{
    public const FIELD = 'configurable_links';

    public function extract(array $post, DataObject $entity, int $storeId): array
    {
        $form = $post['_form_product'] ?? null;
        $raw = $post['_post'] ?? [];
        if (!$form instanceof Product || $form->getTypeId() !== Configurable::TYPE_CODE) {
            return [];
        }
        if (!array_key_exists('associated_product_ids_serialized', $raw)) {
            return [];
        }

        $ids = json_decode((string) $raw['associated_product_ids_serialized'], true);
        $attributes = [];
        foreach ((array) ($raw['product']['configurable_attributes_data'] ?? []) as $attribute) {
            if (is_array($attribute) && !empty($attribute['code'])) {
                $attributes[] = (string) $attribute['code'];
            }
        }
        if (!$attributes) {
            $attributes = $this->currentAttributes($form);
        }

        return [self::FIELD => Value::json($this->normalise($attributes, is_array($ids) ? $ids : []))];
    }

    public function current(DataObject $entity, int $storeId, ?array $fields = null): array
    {
        if (($fields !== null && !in_array(self::FIELD, $fields, true)) || !$entity instanceof Product || $entity->getTypeId() !== Configurable::TYPE_CODE) {
            return [];
        }
        /** @var Configurable $type */
        $type = $entity->getTypeInstance();

        return [self::FIELD => Value::json($this->normalise($this->currentAttributes($entity), (array) $type->getUsedProductIds($entity)))];
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
        if ($entity->getTypeId() !== Configurable::TYPE_CODE) {
            throw new \InvalidArgumentException('Configurable links can only be applied to a configurable product.');
        }
        if ($values[self::FIELD]->isInherit()) {
            throw new \InvalidArgumentException('Configurable links cannot inherit.');
        }
        $staged = $this->normalise(
            (array) ($values[self::FIELD]->value['attributes'] ?? []),
            (array) ($values[self::FIELD]->value['products'] ?? [])
        );
        if ($staged['attributes'] !== $this->currentAttributes($entity)) {
            throw new \InvalidArgumentException('Changing the configurable attributes of a product is not supported by MageDrop; only the associated products can be staged.');
        }

        // Leave the loaded configurable options untouched (an empty set would delete them); only replace the links
        $extension = $entity->getExtensionAttributes();
        $extension->setConfigurableProductLinks($staged['products']);
        $entity->setExtensionAttributes($extension);
    }

    public function overlay(DataObject $entity, array $values): void
    {
    }

    public function toFormData(array $data, array $values): array
    {
        return $data;
    }

    /**
     * @return string[]
     */
    private function currentAttributes(Product $product): array
    {
        /** @var Configurable $type */
        $type = $product->getTypeInstance();
        $codes = [];
        foreach ((array) $type->getConfigurableAttributesAsArray($product) as $attribute) {
            if (!empty($attribute['attribute_code'])) {
                $codes[] = (string) $attribute['attribute_code'];
            }
        }
        sort($codes);

        return $codes;
    }

    private function normalise(array $attributes, array $productIds): array
    {
        $attributes = array_values(array_unique(array_map('strval', $attributes)));
        sort($attributes);
        $ids = [];
        foreach ($productIds as $id) {
            if ((int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }
        sort($ids, SORT_NUMERIC);

        return ['attributes' => $attributes, 'products' => array_values($ids)];
    }
}
