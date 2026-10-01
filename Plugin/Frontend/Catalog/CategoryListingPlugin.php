<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Frontend\Catalog;

use MageDrop\Magento2\Model\Preview\ListingDelta;
use MageDrop\Magento2\Model\Preview\State;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Layer\ItemCollectionProviderInterface;

/**
 * Marks the native category listing collection (every engine's provider implements
 * this interface) so ListingDelta can apply staged assignment changes to the page
 * it loads. The collection itself is not replaced or altered before loading.
 */
class CategoryListingPlugin
{
    public function __construct(
        private State $state
    ) {
    }

    public function afterGetCollection(ItemCollectionProviderInterface $subject, $result, $category = null)
    {
        if ($this->state->isActive() && $category instanceof Category && is_object($result) && method_exists($result, 'setFlag')) {
            $result->setFlag(ListingDelta::FLAG_CATEGORY, $category);
        }

        return $result;
    }
}
