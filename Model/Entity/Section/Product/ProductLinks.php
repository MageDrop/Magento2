<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Section\Product;

use MageDrop\Magento2\Model\Entity\Section\LoadsIntoEntityInterface;
use MageDrop\Magento2\Model\Entity\Section\SectionHandlerInterface;
use MageDrop\Magento2\Model\Entity\Value;
use Magento\Catalog\Api\Data\ProductLinkInterface;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\DataObject;

/**
 * Related / up-sell / cross-sell links: [{type, sku, position}]. Global scope.
 * Preview cannot overlay these (the storefront reads link collections from the DB).
 */
class ProductLinks implements SectionHandlerInterface, LoadsIntoEntityInterface
{
    public const FIELD = 'product_links';

    /** Product data key the staged links travel on to StagedLinksPlugin (admin "Load from Release") */
    public const FORM_LINKS = 'magedrop_staged_links';

    /** Grouped product type's cache of its children (Grouped::getAssociatedProducts()) */
    private const GROUPED_CHILDREN = '_cache_instance_associated_products';

    public function __construct(
        private ProductLinkInterfaceFactory $linkFactory,
        private ProductCollectionFactory $productCollectionFactory
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
        $entity->setProductLinks($this->toLinks($entity, (array) $values[self::FIELD]->value));
    }

    public function overlay(DataObject $entity, array $values): void
    {
    }

    /**
     * The related/up-sell/cross-sell (and grouped) grids are built from the link repository,
     * which StagedLinksPlugin answers from the product (see loadIntoEntity()).
     */
    public function toFormData(array $data, array $values): array
    {
        return $data;
    }

    /**
     * "Load from Release": Magento's link grids read an existing product's links from the
     * database, so put the staged set on the product for StagedLinksPlugin to return instead.
     */
    public function loadIntoEntity(DataObject $product, array $values): void
    {
        if (!$product instanceof Product || !isset($values[self::FIELD]) || $values[self::FIELD]->isInherit()) {
            return;
        }
        $rows = $this->normalise((array) $values[self::FIELD]->value);
        // Read the saved quantities before FORM_LINKS is set: from then on StagedLinksPlugin
        // answers the link repository with the staged links
        $currentQty = $product->getTypeId() === 'grouped' ? $this->currentGroupedQty($product) : [];
        $product->setData(self::FORM_LINKS, $rows);
        if ($product->getTypeId() === 'grouped') {
            $product->setData(self::GROUPED_CHILDREN, $this->groupedChildren($rows, $currentQty));
        }
    }

    /**
     * The grouped panel lists the type's associated products (children with qty and position),
     * not the link repository: build that list for the staged "associated" links.
     *
     * @return Product[]
     */
    private function groupedChildren(array $rows, array $currentQty): array
    {
        $rows = array_values(array_filter($rows, fn (array $row) => $row['type'] === 'associated'));
        if (!$rows) {
            return [];
        }
        $collection = $this->productCollectionFactory->create()
            ->addAttributeToSelect(['name', 'price', 'image', 'thumbnail', 'status'])
            ->addAttributeToFilter('sku', ['in' => array_column($rows, 'sku')]);
        $bySku = [];
        foreach ($collection as $child) {
            $bySku[(string) $child->getSku()] = $child;
        }
        $children = [];
        foreach ($rows as $row) {
            if (isset($bySku[$row['sku']])) {
                // Releases staged before quantities were kept have none: show the current one
                $qty = $row['qty'] ?? $currentQty[$row['sku']] ?? 0;
                $children[] = $bySku[$row['sku']]->setQty($qty)->setPosition($row['position']);
            }
        }

        return $children;
    }

    /**
     * The grouped product's saved default quantity per child sku.
     *
     * @return array<string, float>
     */
    private function currentGroupedQty(Product $product): array
    {
        $qty = [];
        foreach ($this->normalise((array) $product->getProductLinks()) as $row) {
            if ($row['type'] === 'associated' && array_key_exists('qty', $row)) {
                $qty[$row['sku']] = $row['qty'];
            }
        }

        return $qty;
    }

    /**
     * Link objects for a staged set, as the link repository would return them.
     *
     * @return ProductLinkInterface[]
     */
    public function toLinks(Product $product, array $rows): array
    {
        $links = [];
        foreach ($this->normalise($rows) as $row) {
            /** @var ProductLinkInterface $link */
            $link = $this->linkFactory->create();
            $link->setSku($product->getSku())
                ->setLinkedProductSku($row['sku'])
                ->setLinkType($row['type'])
                ->setPosition($row['position']);
            if (array_key_exists('qty', $row)) {
                $extension = $link->getExtensionAttributes();
                if ($extension && method_exists($extension, 'setQty')) {
                    $extension->setQty($row['qty']);
                    $link->setExtensionAttributes($extension);
                }
            }
            $links[] = $link;
        }

        return $links;
    }

    /**
     * @param ProductLinkInterface[]|array[] $links
     */
    private function normalise(array $links): array
    {
        $out = [];
        foreach ($links as $link) {
            $qty = null;
            if ($link instanceof ProductLinkInterface) {
                $type = (string) $link->getLinkType();
                $sku = (string) $link->getLinkedProductSku();
                $position = (int) $link->getPosition();
                $extension = $link->getExtensionAttributes();
                $qty = $extension && method_exists($extension, 'getQty') ? $extension->getQty() : null;
            } elseif (is_array($link)) {
                $type = (string) ($link['type'] ?? $link['link_type'] ?? '');
                $sku = (string) ($link['sku'] ?? $link['linked_product_sku'] ?? '');
                $position = (int) ($link['position'] ?? 0);
                $qty = $link['qty'] ?? null;
            } else {
                continue;
            }
            if ($type === '' || $sku === '') {
                continue;
            }
            $row = ['type' => $type, 'sku' => $sku, 'position' => $position];
            // A grouped product's default quantity per child lives on the "associated" link
            // (releases staged before it was kept have none: leave those quantities alone)
            if ($type === 'associated' && $qty !== null && $qty !== '') {
                $row['qty'] = (float) $qty;
            }
            $out[] = $row;
        }
        usort($out, fn ($a, $b) => [$a['type'], $a['position'], $a['sku']] <=> [$b['type'], $b['position'], $b['sku']]);

        return $out;
    }
}
