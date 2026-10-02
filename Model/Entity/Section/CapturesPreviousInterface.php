<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Section;

use MageDrop\Magento2\Model\Entity\Value;
use Magento\Framework\DataObject;

/**
 * Optional for section handlers whose store-view state is more than "overridden or
 * inherited" (e.g. the gallery: per-image store rows plus role overrides). The value
 * returned is what an apply reports as the previous value, so a rollback applies it
 * and recreates exactly the prior store-view situation.
 */
interface CapturesPreviousInterface
{
    /**
     * @return Value|null null to use the default capture (current() / inherit)
     */
    public function previous(DataObject $entity, string $field, int $storeId): ?Value;

    /**
     * Whether the field has store-view state of its own (reported as scopable, so a
     * store-view revision restore may change it) although it is not a scopable attribute.
     */
    public function hasStoreViewState(string $field): bool;
}
