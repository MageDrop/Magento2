<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Adapter;

use MageDrop\Magento2\Model\Entity\AbstractAdapter;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Framework\DataObject;

class CmsBlock extends AbstractAdapter
{
    public function __construct(
        private BlockRepositoryInterface $blockRepository,
        array $sections = []
    ) {
        parent::__construct('cms_block', 'CMS Block', 'CMS Blocks', 'block_id', 'cms/block/edit', $sections, false);
    }

    public function load(string $entityId, int $storeId): DataObject
    {
        /** @var \Magento\Cms\Model\Block $block */
        $block = $this->blockRepository->getById((int) $entityId);

        return $block;
    }

    public function getTitle(DataObject $entity): ?string
    {
        $title = $entity->getData('title');

        return is_scalar($title) && $title !== '' ? (string) $title : null;
    }

    public function save(DataObject $entity, int $storeId, array $appliedFields = []): void
    {
        /** @var \Magento\Cms\Api\Data\BlockInterface $entity */
        $this->blockRepository->save($entity);
    }
}
