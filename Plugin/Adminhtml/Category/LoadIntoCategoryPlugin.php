<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Adminhtml\Category;

use MageDrop\Magento2\Model\Entity\AbstractAdapter;
use MageDrop\Magento2\Model\Entity\AdapterPool;
use MageDrop\Magento2\Model\Preview\ReleaseValues;
use MageDrop\Magento2\Model\Service\ApiClient;
use MageDrop\Magento2\Plugin\Adminhtml\ScopeOverridesPlugin;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Category\DataProvider;
use Magento\Framework\App\RequestInterface;
use Psr\Log\LoggerInterface;

/**
 * "Load from Release" (?magedrop_load=<release>) for the parts of the category edit page
 * Magento builds from the category rather than from the form data: "Use Default Value"
 * (ScopeOverridesPlugin) and "Products in Category". Hooked where the form gets its category,
 * which the data provider does before it prepares the form's metadata; it is the category the
 * controller registered, so the products grid sees the same values.
 */
class LoadIntoCategoryPlugin
{
    private const ENTITY_TYPE = 'catalog_category';
    private const LOADED = 'magedrop_loaded_into_category';

    public function __construct(
        private ApiClient $apiClient,
        private AdapterPool $adapterPool,
        private ReleaseValues $releaseValues,
        private RequestInterface $request,
        private LoggerInterface $logger
    ) {
    }

    public function afterGetCurrentCategory(DataProvider $subject, $category)
    {
        $releaseId = (int) $this->request->getParam('magedrop_load', 0);
        if (!$releaseId || !$category instanceof Category || !$category->getId()
            || (int) $category->getData(self::LOADED) === $releaseId
            || !$this->apiClient->isEnabled()
        ) {
            return $category;
        }
        $category->setData(self::LOADED, $releaseId);

        try {
            $adapter = $this->adapterPool->get(self::ENTITY_TYPE);
            if ($adapter instanceof AbstractAdapter) {
                $storeId = $adapter->resolveStoreId($this->request);
                $values = $this->releaseValues->get($releaseId, self::ENTITY_TYPE, (string) $category->getId(), $storeId);
                if ($values) {
                    $category->setData(
                        ScopeOverridesPlugin::DATA_KEY,
                        $this->releaseValues->storeOverrides($releaseId, self::ENTITY_TYPE, (string) $category->getId(), $storeId)
                    );
                    $adapter->loadIntoEntity($category, $values);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('MageDrop load into category error: ' . $e->getMessage(), ['exception' => $e]);
        }

        return $category;
    }

    /**
     * The category form's data is the category's data wholesale: keep MageDrop's internal
     * keys out of it, so they are not posted back with the form.
     */
    public function afterGetData(DataProvider $subject, $result)
    {
        if (!is_array($result)) {
            return $result;
        }
        foreach ($result as &$row) {
            if (is_array($row)) {
                unset($row[ScopeOverridesPlugin::DATA_KEY], $row[self::LOADED]);
            }
        }
        unset($row);

        return $result;
    }
}
