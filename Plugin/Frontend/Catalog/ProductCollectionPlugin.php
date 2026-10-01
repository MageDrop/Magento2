<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Frontend\Catalog;

use MageDrop\Magento2\Model\Preview\ListingDelta;
use MageDrop\Magento2\Model\Preview\Overlay;
use Magento\Framework\Data\Collection;

/**
 * Overlay staged product data on listing collections (category pages, search,
 * widgets). The search Fulltext collection extends the product collection so
 * it is covered too. Category listings also get the staged membership delta.
 */
class ProductCollectionPlugin
{
    public function __construct(
        private Overlay $overlay,
        private ListingDelta $listingDelta
    ) {
    }

    public function afterLoad(Collection $subject, $result)
    {
        $this->listingDelta->apply($subject);
        $this->overlay->applyToCollection($subject, 'catalog_product');

        return $result;
    }
}
