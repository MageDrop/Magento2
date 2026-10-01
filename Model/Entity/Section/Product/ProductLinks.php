<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Section\Product;

use MageDrop\Magento2\Model\Entity\Section\SectionHandlerInterface;
use MageDrop\Magento2\Model\Entity\Value;
use Magento\Catalog\Api\Data\ProductLinkInterface;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory;
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;

/**
 * Related / up-sell / cross-sell links: [{type, sku, position}]. Global scope.
 * Preview cannot overlay these (the storefront reads link collections from the DB).
 */
class ProductLinks implements SectionHandlerInterface
{
    public const FIELD = 'product_links';

    public function __construct(
        private ProductLinkInterfaceFactory $linkFactory
    ) {
    }

    public function extract(array $post, DataObject $entity, int $storeId): array
    {
        $form = $post['_form_product'] ?? null;
        if (!$form instanceof Product || !array_key_exists('links', $post['_post'] ?? [])) {
            return [];
        }

        return [self::FIELD => Value::json($this->normalise((array) $form->getProductLinks()))];
    }

    public function current(DataObject $entity, int $storeId, ?array $fields = null): array
    {
        if (($fields !== null && !in_array(self::FIELD, $fields, true)) || !$entity instanceof Product) {
            return [];
        }

        return [self::FIELD => Value::json($this->normalise((array) $entity->getProductLinks()))];
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
            throw new \InvalidArgumentException('Product links cannot inherit.');
        }
        $links = [];
        foreach ($this->normalise((array) $values[self::FIELD]->value) as $row) {
            /** @var ProductLinkInterface $link */
            $link = $this->linkFactory->create();
            $link->setSku($entity->getSku())
                ->setLinkedProductSku($row['sku'])
                ->setLinkType($row['type'])
                ->setPosition($row['position']);
            $links[] = $link;
        }
        $entity->setProductLinks($links);
    }

    public function overlay(DataObject $entity, array $values): void
    {
    }

    public function toFormData(array $data, array $values): array
    {
        return $data; // the links grids need product ids/names; loaded back in a later phase
    }

    /**
     * @param ProductLinkInterface[]|array[] $links
     */
    private function normalise(array $links): array
    {
        $out = [];
        foreach ($links as $link) {
            if ($link instanceof ProductLinkInterface) {
                $type = (string) $link->getLinkType();
                $sku = (string) $link->getLinkedProductSku();
                $position = (int) $link->getPosition();
            } elseif (is_array($link)) {
                $type = (string) ($link['type'] ?? $link['link_type'] ?? '');
                $sku = (string) ($link['sku'] ?? $link['linked_product_sku'] ?? '');
                $position = (int) ($link['position'] ?? 0);
            } else {
                continue;
            }
            if ($type === '' || $sku === '') {
                continue;
            }
            $out[] = ['type' => $type, 'sku' => $sku, 'position' => $position];
        }
        usort($out, fn ($a, $b) => [$a['type'], $a['position'], $a['sku']] <=> [$b['type'], $b['position'], $b['sku']]);

        return $out;
    }
}
