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
     * Same pattern as CmsBlockPlugin: verify store membership ourselves, then
     * drop storeId so ResourceModel\Page::_getLoadSelect skips the is_active filter.
     */
    public function aroundLoad(Page $subject, callable $proceed, $modelId, $field = null): Page
    {
        if (!$this->state->isActive()) {
            return $proceed($modelId, $field);
        }

        $storeId = $subject->getStoreId();

        if ($storeId !== null && is_numeric($modelId)) {
            if (!$this->pageExistsInStore((int) $modelId, (int) $storeId)) {
                return $proceed($modelId, $field);
            }

            $subject->setStoreId(null);
            $result = $proceed($modelId, $field);
            $subject->setStoreId($storeId);
        } else {
            $result = $proceed($modelId, $field);
        }

        if ($result->getId()) {
            $this->overlay->applyTo($result, 'cms_page');
        }

        return $result;
    }

    private function pageExistsInStore(int $pageId, int $storeId): bool
    {
        $linkField = $this->metadataPool->getMetadata(PageInterface::class)->getLinkField();
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('cms_page_store'), [$linkField])
            ->where($linkField . ' = ?', $pageId)
            ->where('store_id IN (?)', [$storeId, Store::DEFAULT_STORE_ID])
            ->limit(1);

        return (bool) $connection->fetchOne($select);
    }
}
