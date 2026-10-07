<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Adminhtml\Product;

use MageDrop\Magento2\Model\Entity\AbstractAdapter;
use MageDrop\Magento2\Model\Entity\AdapterPool;
use MageDrop\Magento2\Model\Preview\ReleaseValues;
use MageDrop\Magento2\Model\Service\ApiClient;
use MageDrop\Magento2\Plugin\Adminhtml\ScopeOverridesPlugin;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Controller\Adminhtml\Product\Builder;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Psr\Log\LoggerInterface;

/**
 * "Load from Release" (?magedrop_load=<release>, also the Quick Preview reload) for the parts
 * of the product edit page Magento builds from the product rather than from the form data:
 * the image gallery and roles, the related/up-sell/cross-sell and grouped grids, configurable
 * children. Their staged values go on the product before the page is built, so Magento renders
 * them itself (LoadChangesPlugin still fills the rest of the form data).
 */
class LoadIntoProductPlugin
{
    private const ENTITY_TYPE = 'catalog_product';

    public function __construct(
        private ApiClient $apiClient,
        private AdapterPool $adapterPool,
        private ReleaseValues $releaseValues,
        private LoggerInterface $logger
    ) {
    }

    public function afterBuild(Builder $subject, ProductInterface $product, RequestInterface $request): ProductInterface
    {
        $releaseId = (int) $request->getParam('magedrop_load', 0);
        if (!$releaseId || !$product->getId() || !$product instanceof DataObject
            || ($request instanceof Http && $request->getFullActionName() !== 'catalog_product_edit')
            || !$this->apiClient->isEnabled()
        ) {
            return $product;
        }

        try {
            $adapter = $this->adapterPool->get(self::ENTITY_TYPE);
            if (!$adapter instanceof AbstractAdapter) {
                return $product;
            }
            $storeId = $adapter->resolveStoreId($request);
            $values = $this->releaseValues->get($releaseId, self::ENTITY_TYPE, (string) $product->getId(), $storeId);
            if ($values) {
                $product->setData(
                    ScopeOverridesPlugin::DATA_KEY,
                    $this->releaseValues->storeOverrides($releaseId, self::ENTITY_TYPE, (string) $product->getId(), $storeId)
                );
                $adapter->loadIntoEntity($product, $values);
            }
        } catch (\Throwable $e) {
            $this->logger->error('MageDrop load into product error: ' . $e->getMessage(), ['exception' => $e]);
        }

        return $product;
    }
}
