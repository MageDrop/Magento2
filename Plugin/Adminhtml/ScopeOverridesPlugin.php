<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Adminhtml;

use MageDrop\Magento2\Model\Entity\Section\LoadsIntoEntityInterface;
use Magento\Catalog\Model\Attribute\ScopeOverriddenValue;
use Magento\Framework\DataObject;

/**
 * "Load from Release" at a store view: Magento ticks an attribute's "Use Default Value"
 * from whether the view has its own value in the database (ScopeOverriddenValue). For the
 * entity a release was loaded into, answer from the release instead: a staged store-view
 * value is an override (box unticked), a staged "inherit" is not (box ticked).
 */
class ScopeOverridesPlugin
{
    public const DATA_KEY = LoadsIntoEntityInterface::SCOPE_OVERRIDES;

    public function aroundContainsValue(
        ScopeOverriddenValue $subject,
        callable $proceed,
        $entityType,
        $entity,
        $attributeCode,
        $storeId
    ) {
        if ($entity instanceof DataObject) {
            $overrides = $entity->getData(self::DATA_KEY);
            if (is_array($overrides) && array_key_exists((string) $attributeCode, $overrides)
                && (int) $storeId === (int) $entity->getStoreId()
            ) {
                return (bool) $overrides[(string) $attributeCode];
            }
        }

        return $proceed($entityType, $entity, $attributeCode, $storeId);
    }
}
