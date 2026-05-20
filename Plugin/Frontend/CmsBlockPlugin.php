<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Frontend;

use MageDrop\Magento2\Model\Preview\Overlay;
use MageDrop\Magento2\Model\Preview\State;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Model\Block;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Store\Model\Store;

class CmsBlockPlugin
{
    public function __construct(
        private State $state,
        private Overlay $overlay,
        private ResourceConnection $resourceConnection,
        private MetadataPool $metadataPool
    ) {
    }

    /**
     * The is_active=1 filter in ResourceModel\Block::_getLoadSelect is gated on
     * storeId being set. We need to bypass that filter for preview without losing
     * store scoping. Approach: verify store membership ourselves (one short query
     * against cms_block_store with no is_active condition), then drop storeId on
     * the subject so the resource load skips both filters.
     */
    public function aroundLoad(Block $subject, callable $proceed, $modelId, $field = null): Block
    {
        if (!$this->state->isActive()) {
            return $proceed($modelId, $field);
        }

        $storeId = $subject->getStoreId();

        if ($storeId !== null && is_numeric($modelId)) {
            if (!$this->blockExistsInStore((int) $modelId, (int) $storeId)) {
                return $proceed($modelId, $field);
            }

            $subject->setStoreId(null);
            $result = $proceed($modelId, $field);
            $subject->setStoreId($storeId);
        } else {
            $result = $proceed($modelId, $field);
        }

        if ($result->getId()) {
            $this->overlay->applyTo($result, 'cms_block');
        }

        return $result;
    }

    private function blockExistsInStore(int $blockId, int $storeId): bool
    {
        $linkField = $this->metadataPool->getMetadata(BlockInterface::class)->getLinkField();
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('cms_block_store'), [$linkField])
            ->where($linkField . ' = ?', $blockId)
            ->where('store_id IN (?)', [$storeId, Store::DEFAULT_STORE_ID])
            ->limit(1);

        return (bool) $connection->fetchOne($select);
    }
}
