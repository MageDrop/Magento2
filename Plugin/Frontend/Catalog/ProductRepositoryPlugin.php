<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Frontend\Catalog;

use MageDrop\Magento2\Model\Preview\Overlay;
use MageDrop\Magento2\Model\Preview\State;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;

/**
 * Overlay staged product data on repository loads (product page, cart, widgets).
 * The repository caches instances and this runs on every call, so the overlay
 * must be (and is) idempotent.
 */
class ProductRepositoryPlugin
{
    public function __construct(
        private State $state,
        private Overlay $overlay
    ) {
    }

    public function afterGetById(ProductRepositoryInterface $subject, ProductInterface $result): ProductInterface
    {
        return $this->apply($result);
    }

    public function afterGet(ProductRepositoryInterface $subject, ProductInterface $result): ProductInterface
    {
        return $this->apply($result);
    }

    private function apply(ProductInterface $product): ProductInterface
    {
        if ($this->state->isActive() && $product->getId() && $product instanceof \Magento\Framework\DataObject) {
            $this->overlay->applyTo($product, 'catalog_product');
        }

        return $product;
    }
}
