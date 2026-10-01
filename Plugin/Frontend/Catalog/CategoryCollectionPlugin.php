<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Frontend\Catalog;

use MageDrop\Magento2\Model\Preview\Overlay;
use MageDrop\Magento2\Model\Preview\State;
use Magento\Framework\Data\Collection;

/**
 * Overlay staged category data on collection loads (Luma Topmenu, Hyvä
 * navigation, category lists). Attached to both the EAV and the flat
 * category collections.
 */
class CategoryCollectionPlugin
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
                $this->overlay->applyTo($item, 'catalog_category');
            }
        }

        return $result;
    }
}
