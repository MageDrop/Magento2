<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Preview;

use MageDrop\Magento2\Model\Entity\Section\Category\Products as CategoryProducts;
use MageDrop\Magento2\Model\Entity\Section\Product\Assignments;
use MageDrop\Magento2\Model\Entity\Value;
use Magento\Catalog\Model\Category;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * How a release changes which products a category lists, for preview.
 *
 * Listings come from the search index, so the staged assignment changes (the
 * category's "Products in Category" and products' own categories) are turned
 * into a small delta that ListingDelta applies to the loaded page of the native
 * listing collection. Work is proportional to the change, not the category size.
 */
class CategoryListing
{
    /** @var array<string, array|null> */
    private array $cache = [];

    public function __construct(
        private Overlay $overlay,
        private ResourceConnection $resource,
        private StoreManagerInterface $storeManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @return array{added: array<int, int>, removed: array<int, true>}|null productId => position for
     *         additions; null when the release does not change this category's membership
     */
    public function delta(Category $category): ?array
    {
        $categoryId = (int) $category->getId();
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable) {
            return null;
        }
        $key = $storeId . ':' . $categoryId;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        try {
            return $this->cache[$key] = $this->compute($category, $categoryId);
        } catch (\Throwable $e) {
            $this->logger->error('MageDrop category listing preview error: ' . $e->getMessage());

            return $this->cache[$key] = null;
        }
    }

    private function compute(Category $category, int $categoryId): ?array
    {
        $connection = $this->resource->getConnection();
        $stagedList = $this->overlay->stagedValues('catalog_category', $categoryId)[CategoryProducts::FIELD] ?? null;
        $productIds = $this->overlay->stagedEntityIds('catalog_product');
        if (!$stagedList instanceof Value && !$productIds) {
            return null;
        }

        $direct = array_map('intval', $connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName('catalog_category_product'), ['product_id', 'position'])
                ->where('category_id = ?', $categoryId)
        ));

        $added = [];
        $removed = [];

        // The category's own staged product list replaces its direct assignments
        if ($stagedList instanceof Value && !$stagedList->isInherit() && is_array($stagedList->value)) {
            $positions = [];
            foreach ($stagedList->value as $productId => $position) {
                $positions[(int) $productId] = (int) $position;
            }
            foreach ($positions as $productId => $position) {
                if (!isset($direct[$productId]) || $direct[$productId] !== $position) {
                    $added[$productId] = $position; // new, or moved
                }
            }
            $removed = array_fill_keys(array_keys(array_diff_key($direct, $positions)), true);
        }

        // Products whose staged categories add or drop this one
        foreach ($productIds as $productId) {
            $categories = $this->overlay->stagedValues('catalog_product', $productId)[Assignments::FIELDS[0]] ?? null;
            if (!$categories instanceof Value || $categories->isInherit() || !is_array($categories->value)) {
                continue;
            }
            $inStaged = in_array($categoryId, array_map('intval', $categories->value), true);
            if ($inStaged && !isset($direct[$productId]) && !isset($added[$productId])) {
                $added[$productId] = 0; // Magento's position for a new assignment
                unset($removed[$productId]);
            } elseif (!$inStaged && isset($direct[$productId])) {
                $removed[$productId] = true;
                unset($added[$productId]);
            }
        }

        // A product still assigned to a child of an anchor category stays listed
        if ($removed && $category->getIsAnchor()) {
            $viaChildren = $connection->fetchCol(
                $connection->select()
                    ->from(['ccp' => $this->resource->getTableName('catalog_category_product')], ['product_id'])
                    ->join(['cce' => $this->resource->getTableName('catalog_category_entity')], 'cce.entity_id = ccp.category_id', [])
                    ->where('cce.path LIKE ?', $category->getPath() . '/%')
                    ->where('ccp.product_id IN (?)', array_keys($removed))
            );
            foreach ($viaChildren as $productId) {
                unset($removed[(int) $productId]);
            }
        }

        return $added || $removed ? ['added' => $added, 'removed' => $removed] : null;
    }
}
