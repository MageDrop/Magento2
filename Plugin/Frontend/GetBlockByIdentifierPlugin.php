<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Frontend;

use MageDrop\Magento2\Model\Preview\Overlay;
use MageDrop\Magento2\Model\Preview\State;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Model\BlockFactory;
use Magento\Cms\Model\GetBlockByIdentifier;
use Magento\Cms\Model\ResourceModel\Block as BlockResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;

/**
 * GetBlockByIdentifier (and the modern BlockByIdentifier block via it) goes via
 * ResourceModel\Block::load() directly — bypassing Cms\Model\Block::load(). That
 * load filters is_active=1 at SQL level when storeId is set, so disabled blocks
 * throw NoSuchEntityException before any plugin overlay can run.
 *
 * Fallback path: on NSE, look up the block_id store-scoped without the is_active
 * filter, then load the row by primary key (which doesn't filter). Overlay applies,
 * and we re-gate on the post-overlay isActive() so preview matches deploy outcome.
 */
class GetBlockByIdentifierPlugin
{
    public function __construct(
        private State $state,
        private Overlay $overlay,
        private BlockFactory $blockFactory,
        private BlockResource $blockResource,
        private ResourceConnection $resourceConnection,
        private MetadataPool $metadataPool
    ) {
    }

    public function aroundExecute(
        GetBlockByIdentifier $subject,
        callable $proceed,
        string $identifier,
        int $storeId
    ) {
        if (!$this->state->isActive()) {
            return $proceed($identifier, $storeId);
        }
        try {
            $result = $proceed($identifier, $storeId);
        } catch (NoSuchEntityException $e) {
            $result = $this->loadIgnoringActive($identifier, $storeId);
            if (!$result) {
                throw $e;
            }
        }
        $this->overlay->applyTo($result, 'cms_block');
        if (!$result->isActive()) {
            throw new NoSuchEntityException(
                __('The CMS block with the "%1" ID doesn\'t exist.', $identifier)
            );
        }
        return $result;
    }

    private function loadIgnoringActive(string $identifier, int $storeId): ?BlockInterface
    {
        $blockId = $this->findBlockId($identifier, $storeId);
        if (!$blockId) {
            return null;
        }

        // Load by primary key with no storeId set on the block — bypasses the
        // store-gated is_active filter in ResourceModel\Block::_getLoadSelect.
        $block = $this->blockFactory->create();
        $this->blockResource->load($block, $blockId);

        return $block->getId() ? $block : null;
    }

    private function findBlockId(string $identifier, int $storeId): ?int
    {
        $metadata = $this->metadataPool->getMetadata(BlockInterface::class);
        $linkField = $metadata->getLinkField();

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
            ->where('cb.identifier = ?', $identifier)
            ->where('cbs.store_id IN (?)', [(int) $storeId, Store::DEFAULT_STORE_ID])
            ->order('cbs.store_id DESC')
            ->limit(1);

        $blockId = $connection->fetchOne($select);

        return $blockId !== false ? (int) $blockId : null;
    }
}
