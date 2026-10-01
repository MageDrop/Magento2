<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model;

use MageDrop\Magento2\Api\PingInterface;
use MageDrop\Magento2\Model\Entity\AdapterPool;
use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Backend\App\Area\FrontNameResolver;
use Magento\Store\Model\StoreManagerInterface;

class Ping implements PingInterface
{
    public function __construct(
        private ApiClient $apiClient,
        private AdapterPool $adapterPool,
        private StoreManagerInterface $storeManager,
        private FrontNameResolver $frontNameResolver
    ) {
    }

    public function ping(): string
    {
        if (!$this->apiClient->isEnabled()) {
            return 'Module is disabled';
        }

        $result = $this->apiClient->handshake($this->capabilities());

        if ($result && isset($result['store_id'])) {
            return 'ok';
        }

        return 'Failed to connect to MageDrop API';
    }

    /**
     * Everything the SaaS needs to know about this installation.
     */
    public function capabilities(): array
    {
        $storeViews = [];
        foreach ($this->storeManager->getStores(false) as $store) {
            $storeViews[] = [
                'id' => (int) $store->getId(),
                'code' => $store->getCode(),
                'name' => $store->getName(),
                'website' => $store->getWebsite()->getName(),
                'is_active' => (bool) $store->getIsActive(),
            ];
        }

        return [
            'module_version' => Version::VERSION,
            'features' => Version::CAPABILITIES,
            'single_store_mode' => $this->storeManager->hasSingleStore(),
            'admin_front_name' => (string) $this->frontNameResolver->getFrontName(true),
            'entity_types' => $this->adapterPool->describe(),
            'store_views' => $storeViews,
        ];
    }
}
