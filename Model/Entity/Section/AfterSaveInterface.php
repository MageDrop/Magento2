<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Section;

use Magento\Framework\DataObject;

/**
 * Optional for section handlers that must adjust rows the entity save itself writes
 * (called by the adapter right after a MageDrop apply has saved the entity).
 */
interface AfterSaveInterface
{
    public function afterSave(DataObject $entity, int $storeId): void;
}
