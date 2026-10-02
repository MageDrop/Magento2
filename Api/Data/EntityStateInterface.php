<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Api\Data;

interface EntityStateInterface
{
    /**
     * @return string
     */
    public function getEntityType(): string;

    /**
     * @return string
     */
    public function getEntityId(): string;

    /**
     * @return int
     */
    public function getStoreId(): int;

    /**
     * @return string|null
     */
    public function getTitle(): ?string;

    /**
     * @return \MageDrop\Magento2\Api\Data\ChangeInterface[]
     */
    public function getFields(): array;

    /**
     * Fields that have a store-level override at the requested store.
     *
     * @return string[]
     */
    public function getOverriddenFields(): array;

    /**
     * Fields that can hold a store-view value at all (empty at the default scope).
     * Anything else is global: changing it at a store view changes every store view.
     *
     * @return string[]
     */
    public function getScopableFields(): array;
}
