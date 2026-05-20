<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Frontend;

use MageDrop\Magento2\Model\Preview\Overlay;
use MageDrop\Magento2\Model\Preview\State;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Model\Page;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Store\Model\Store;

class CmsPagePlugin
{
    public function __construct(
        private State $state,
        private Overlay $overlay,
        private ResourceConnection $resourceConnection,
        private MetadataPool $metadataPool
    ) {
    }

    /**
     * Same pattern as CmsBlockPlugin: resolve the lookup to a page_id ourselves
     * (store-scoped, no is_active filter), then load by primary key with storeId
     * cleared so neither the store nor is_active SQL filters apply.
     */
    public function aroundLoad(Page $subject, callable $proceed, $modelId, $field = null): Page
    {
        if (!$this->state->isActive()) {
            return $proceed($modelId, $field);
        }

        $storeId = $subject->getStoreId();

        if ($storeId !== null) {
            $resolvedId = $this->resolvePageId($modelId, (int) $storeId);

            if (!$resolvedId) {
                return $proceed($modelId, $field);
            }

            $subject->setStoreId(null);
            $result = $proceed($resolvedId, null);
            $subject->setStoreId($storeId);
        } else {
            $result = $proceed($modelId, $field);
        }

        if ($result->getId()) {
            $this->overlay->applyTo($result, 'cms_page');
        }

        return $result;
    }

    /**
     * Resolve a page lookup (by page_id or by identifier) to a numeric page_id,
     * scoped to the given store but without the is_active filter. Picks
     * specific-store over default-store, and the newest page_id as a deterministic
     * tie-breaker when duplicate identifiers exist in the same store.
     */
    private function resolvePageId($modelId, int $storeId): ?int
    {
        $linkField = $this->metadataPool->getMetadata(PageInterface::class)->getLinkField();
        $lookupField = is_numeric($modelId) ? $linkField : 'identifier';
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(
                ['cp' => $this->resourceConnection->getTableName('cms_page')],
                [$linkField]
            )
            ->join(
                ['cps' => $this->resourceConnection->getTableName('cms_page_store')],
                'cp.' . $linkField . ' = cps.' . $linkField,
                []
            )
            ->where('cp.' . $lookupField . ' = ?', $modelId)
            ->where('cps.store_id IN (?)', [$storeId, Store::DEFAULT_STORE_ID])
            ->order('cps.store_id DESC')
            ->order('cp.' . $linkField . ' DESC')
            ->limit(1);

        $pageId = $connection->fetchOne($select);

        return $pageId !== false ? (int) $pageId : null;
    }
}
