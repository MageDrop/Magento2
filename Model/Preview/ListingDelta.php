<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Preview;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Config as CatalogConfig;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Data\Collection;
use Psr\Log\LoggerInterface;

/**
 * Applies a release's category membership delta to the loaded page of the native
 * category listing collection: staged removals drop out, staged additions (and
 * moved products) are placed on the first page at their staged position. The
 * search query, paging, sorting and layered navigation stay untouched, so the
 * product count and filter options reflect the live listing.
 */
class ListingDelta
{
    /** Flag on the listing collection: the category whose membership is previewed. */
    public const FLAG_CATEGORY = 'magedrop_preview_listing_category';

    private const FLAG_APPLIED = 'magedrop_preview_listing_applied';

    public function __construct(
        private CategoryListing $listing,
        private CollectionFactory $collectionFactory,
        private CatalogConfig $catalogConfig,
        private Visibility $visibility,
        private LoggerInterface $logger
    ) {
    }

    public function apply(Collection $collection): void
    {
        $category = $collection->getFlag(self::FLAG_CATEGORY);
        if (!$category instanceof Category || $collection->getFlag(self::FLAG_APPLIED)) {
            return;
        }
        $collection->setFlag(self::FLAG_APPLIED, true);

        $delta = $this->listing->delta($category);
        if ($delta === null) {
            return;
        }

        try {
            $this->rebuild($collection, $category, $delta['added'], $delta['removed']);
        } catch (\Throwable $e) {
            $this->logger->error('MageDrop category listing preview error: ' . $e->getMessage());
        }
    }

    /**
     * @param array<int, int> $added productId => staged position
     * @param array<int, true> $removed
     */
    private function rebuild(Collection $collection, Category $category, array $added, array $removed): void
    {
        $kept = [];
        foreach ($collection->getItems() as $item) {
            $id = (int) $item->getId();
            if (!isset($removed[$id]) && !isset($added[$id])) {
                $kept[] = $item;
            }
        }

        $curPage = method_exists($collection, 'getCurPage') ? (int) $collection->getCurPage() : 1;
        if ($added && $curPage <= 1) {
            $extra = $this->loadProducts($collection, $category, array_keys($added));
            asort($added);
            foreach ($added as $productId => $position) {
                if (isset($extra[$productId])) {
                    array_splice($kept, min(max($position, 0), count($kept)), 0, [$extra[$productId]]);
                }
            }
        }

        $collection->removeAllItems();
        foreach ($kept as $item) {
            $collection->addItem($item);
        }
    }

    /**
     * Only the staged products, prepared like the listing (Layer\Category\CollectionFilter).
     *
     * @param int[] $ids
     * @return array<int, \Magento\Catalog\Model\Product>
     */
    private function loadProducts(Collection $listing, Category $category, array $ids): array
    {
        $products = $this->collectionFactory->create();
        if (method_exists($listing, 'getStoreId')) {
            $products->setStoreId($listing->getStoreId());
        }
        $products->addStoreFilter()
            ->addIdFilter($ids)
            ->addAttributeToSelect($this->catalogConfig->getProductAttributes())
            ->addMinimalPrice()
            ->addFinalPrice()
            ->addTaxPercents()
            ->addUrlRewrite($category->getId())
            ->setVisibility($this->visibility->getVisibleInCatalogIds());

        $byId = [];
        foreach ($products as $product) {
            $byId[(int) $product->getId()] = $product;
        }

        return $byId;
    }
}
