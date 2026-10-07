<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Adminhtml\Product;

use MageDrop\Magento2\Model\Entity\Section\Product\ProductLinks;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductLinkRepositoryInterface;
use Magento\Catalog\Model\Product;

/**
 * The link grids (related/up-sell/cross-sell, grouped) read an existing product's links from
 * the database through the repository; for a product "Load from Release" has put staged links
 * on (ProductLinks::loadIntoEntity()), answer with those so Magento builds the grids from them.
 */
class StagedLinksPlugin
{
    public function __construct(
        private ProductLinks $productLinks
    ) {
    }

    public function aroundGetList(ProductLinkRepositoryInterface $subject, callable $proceed, ProductInterface $product)
    {
        if ($product instanceof Product && $product->hasData(ProductLinks::FORM_LINKS)) {
            return $this->productLinks->toLinks($product, (array) $product->getData(ProductLinks::FORM_LINKS));
        }

        return $proceed($product);
    }
}
