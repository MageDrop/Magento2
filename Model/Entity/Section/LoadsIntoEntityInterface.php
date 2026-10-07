<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Section;

use MageDrop\Magento2\Model\Entity\Value;
use Magento\Framework\DataObject;

/**
 * For sections whose admin form part Magento builds from the entity rather than from the
 * form data (the product gallery, link grids, configurable children): "Load from Release"
 * puts the staged values on the entity before the edit page is built, so Magento renders
 * them itself. Their toFormData() then has nothing to do.
 */
interface LoadsIntoEntityInterface
{
    /**
     * Entity data key, set before loadIntoEntity(): fields the release changes at the edit
     * page's store view itself, field => true (store-view value) / false (inherit).
     */
    public const SCOPE_OVERRIDES = 'magedrop_scope_overrides';

    /**
     * @param array<string, Value> $values
     */
    public function loadIntoEntity(DataObject $entity, array $values): void;
}
