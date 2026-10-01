<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Api;

/**
 * REST surface the MageDrop SaaS uses to read and write entity state through
 * the module's adapters (OAuth integration, ACL MageDrop_Magento2::api).
 */
interface EntityManagementInterface
{
    /**
     * Current normalised state of an entity at a store scope.
     *
     * @param string $type
     * @param string $id
     * @param int $storeId
     * @return \MageDrop\Magento2\Api\Data\EntityStateInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getState(string $type, string $id, int $storeId = 0): \MageDrop\Magento2\Api\Data\EntityStateInterface;

    /**
     * Apply field values to an entity at a store scope and return the previous values.
     *
     * @param string $type
     * @param string $id
     * @param int $storeId
     * @param \MageDrop\Magento2\Api\Data\ChangeInterface[] $changes
     * @return \MageDrop\Magento2\Api\Data\ApplyResultInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function apply(string $type, string $id, int $storeId, array $changes): \MageDrop\Magento2\Api\Data\ApplyResultInterface;

    /**
     * Module version and capabilities.
     *
     * @return string[]
     */
    public function capabilities(): array;
}
