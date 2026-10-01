<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Frontend\Catalog;

use MageDrop\Magento2\Model\Preview\Overlay;
use MageDrop\Magento2\Model\Preview\State;
use Magento\Catalog\Model\Category;

/**
 * Overlay staged category data on single-model loads (CategoryRepository::get,
 * Category\View controller, canShow()).
 */
class CategoryPlugin
{
    public function __construct(
        private State $state,
        private Overlay $overlay
    ) {
    }

    public function afterLoad(Category $subject, $result)
    {
        if ($this->state->isActive() && $subject->getId()) {
            $this->overlay->applyTo($subject, 'catalog_category');
        }

        return $result;
    }
}
