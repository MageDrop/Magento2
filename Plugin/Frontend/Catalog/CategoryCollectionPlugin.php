<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Frontend\Catalog;

use MageDrop\Magento2\Model\Preview\Overlay;
use Magento\Framework\Data\Collection;

/**
 * Overlay staged category data on collection loads (Luma Topmenu, Hyvä
 * navigation, category lists). Attached to both the EAV and the flat
 * category collections.
 */
class CategoryCollectionPlugin
{
    public function __construct(
        private Overlay $overlay
    ) {
    }

    public function afterLoad(Collection $subject, $result)
    {
        $this->overlay->applyToCollection($subject, 'catalog_category');

        return $result;
    }
}
