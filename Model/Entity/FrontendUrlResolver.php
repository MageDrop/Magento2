<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;

/**
 * Absolute storefront URLs for staged entities, via the URL rewrite table so
 * the result matches what the store actually serves. Default scope resolves to
 * the default store view.
 */
class FrontendUrlResolver
{
    public function __construct(
        private UrlFinderInterface $urlFinder,
        private StoreManagerInterface $storeManager,
        private ScopeConfigInterface $scopeConfig
    ) {
    }

    public function forEntity(string $entityType, int $entityId, int $storeId): ?string
    {
        try {
            $store = $this->store($storeId);
            $rewrite = $this->urlFinder->findOneByData([
                UrlRewrite::ENTITY_TYPE => $entityType,
                UrlRewrite::ENTITY_ID => $entityId,
                UrlRewrite::STORE_ID => (int) $store->getId(),
                UrlRewrite::REDIRECT_TYPE => 0,
            ]);
            if (!$rewrite) {
                return null;
            }

            return rtrim((string) $store->getBaseUrl(), '/') . '/' . ltrim((string) $rewrite->getRequestPath(), '/');
        } catch (\Throwable) {
            return null;
        }
    }

    public function forCmsPage(string $identifier, int $storeId): ?string
    {
        try {
            $store = $this->store($storeId);
            $base = rtrim((string) $store->getBaseUrl(), '/') . '/';
            $homeIdentifier = (string) $this->scopeConfig->getValue('web/default/cms_home_page', ScopeInterface::SCOPE_STORE, $store->getId());

            return $identifier === $homeIdentifier ? $base : $base . $identifier;
        } catch (\Throwable) {
            return null;
        }
    }

    private function store(int $storeId): \Magento\Store\Api\Data\StoreInterface
    {
        if ($storeId === Store::DEFAULT_STORE_ID) {
            return $this->storeManager->getDefaultStoreView() ?? $this->storeManager->getStore();
        }

        return $this->storeManager->getStore($storeId);
    }
}
