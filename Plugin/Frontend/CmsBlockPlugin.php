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
     * store scoping. Approach: resolve the requested block to a block_id ourselves
     * (store-scoped, no is_active filter), then load by primary key with storeId
     * cleared so neither the store nor is_active SQL filters apply.
     */
    public function aroundLoad(Block $subject, callable $proceed, $modelId, $field = null): Block
    {
        if (!$this->state->isActive()) {
            return $proceed($modelId, $field);
        }

        $storeId = $subject->getStoreId();

        if ($storeId !== null) {
            $resolvedId = $this->resolveBlockId($modelId, (int) $storeId);

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
            $this->overlay->applyTo($result, 'cms_block');
        }

        return $result;
    }

    /**
     * Resolve a block lookup (by block_id or by identifier) to a numeric block_id,
     * scoped to the given store but without the is_active filter. Picks
     * specific-store over default-store, and the newest block_id as a deterministic
     * tie-breaker when duplicate identifiers exist in the same store.
     */
    private function resolveBlockId($modelId, int $storeId): ?int
    {
        $linkField = $this->metadataPool->getMetadata(BlockInterface::class)->getLinkField();
        $lookupField = is_numeric($modelId) ? $linkField : 'identifier';
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(
                ['cb' => $this->resourceConnection->getTableName('cms_block')],
                [$linkField]
            )
            ->join(
                ['cbs' => $this->resourceConnection->getTableName('cms_block_store')],
                'cb.' . $linkField . ' = cbs.' . $linkField,
                []
            )
            ->where('cb.' . $lookupField . ' = ?', $modelId)
            ->where('cbs.store_id IN (?)', [$storeId, Store::DEFAULT_STORE_ID])
            ->order('cbs.store_id DESC')
            ->order('cb.' . $linkField . ' DESC')
            ->limit(1);

        $blockId = $connection->fetchOne($select);

        return $blockId !== false ? (int) $blockId : null;
    }
}
