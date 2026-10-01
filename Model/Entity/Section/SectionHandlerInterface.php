<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Section;

use MageDrop\Magento2\Model\Entity\Value;
use Magento\Framework\DataObject;

/**
 * A section handler owns a group of fields on an entity (scalar attributes,
 * images, product assignments, a third-party form tab...). Third-party modules
 * add their own handler to an adapter's `sections` DI array to make their data
 * stageable, previewable and deployable through MageDrop.
 */
interface SectionHandlerInterface
{
    /**
     * Fields owned by this handler that are present in the admin form POST,
     * mapped to the staged Value they would have after save.
     *
     * @return array<string, Value>
     */
    public function extract(array $post, DataObject $entity, int $storeId): array;

    /**
     * Current (live) Values for $fields, or for every handled field when null.
     *
     * @return array<string, Value>
     */
    public function current(DataObject $entity, int $storeId, ?array $fields = null): array;

    public function handles(string $field, DataObject $entity): bool;

    /**
     * Whether $field can hold a store-view specific value at all (store/website
     * scoped EAV attribute, per-store image...). Global data returns false.
     */
    public function isScopable(DataObject $entity, string $field): bool;

    /**
     * Whether $field has a store-level override at $storeId. Global-scope data
     * must return true (there is nothing to inherit from).
     */
    public function isOverridden(DataObject $entity, string $field, int $storeId): bool;

    /**
     * Mutate the in-memory entity; the adapter saves afterwards.
     *
     * @param array<string, Value> $values
     */
    public function apply(DataObject $entity, array $values, int $storeId): void;

    /**
     * Overlay staged values on a frontend model for preview (no persistence).
     *
     * @param array<string, Value> $values
     */
    public function overlay(DataObject $entity, array $values): void;

    /**
     * Merge staged values into admin form data-provider output.
     *
     * @param array<string, Value> $values
     */
    public function toFormData(array $data, array $values): array;
}
