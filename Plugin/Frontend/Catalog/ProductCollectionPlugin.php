<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Frontend\Catalog;

use MageDrop\Magento2\Model\Preview\Overlay;
use MageDrop\Magento2\Model\Preview\State;
use Magento\Framework\Data\Collection;

/**
 * Overlay staged product data on listing collections (category pages, search,
 * widgets). The search Fulltext collection extends the product collection so
 * it is covered too.
 */
class ProductCollectionPlugin
{
    public function __construct(
        private State $state,
        private Overlay $overlay
    ) {
    }

    public function afterLoad(Collection $subject, $result)
    {
        if (!$this->state->isActive()) {
            return $result;
        }

        foreach ($subject->getItems() as $item) {
            if ($item->getId()) {
                $this->overlay->applyTo($item, 'catalog_product');
            }
        }

        return $result;
    }
}
