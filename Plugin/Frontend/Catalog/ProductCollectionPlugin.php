<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Frontend\Catalog;

use MageDrop\Magento2\Model\Preview\Overlay;
use Magento\Framework\Data\Collection;

/**
 * Overlay staged product data on listing collections (category pages, search,
 * widgets). The search Fulltext collection extends the product collection so
 * it is covered too.
 */
class ProductCollectionPlugin
{
    public function __construct(
        private Overlay $overlay
    ) {
    }

    public function afterLoad(Collection $subject, $result)
    {
        $this->overlay->applyToCollection($subject, 'catalog_product');

        return $result;
    }
}
