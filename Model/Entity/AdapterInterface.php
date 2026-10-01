<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;

/**
 * An entity adapter teaches MageDrop how to load, diff, apply, preview and
 * describe one kind of Magento entity. Register additional adapters in the
 * AdapterPool `adapters` DI argument to make custom entities stageable.
 */
interface AdapterInterface
{
    public function getCode(): string;

    public function getLabel(): string;

    public function getPluralLabel(): string;

    /** Request parameter carrying the entity id on the admin edit route. */
    public function getIdParam(): string;

    /** Key of the entity id inside admin form data-provider output. */
    public function getFormIdKey(): string;

    public function getEditRoute(): string;

    /** Route params for redirecting back to the edit form. */
    public function getEditParams(string $entityId, int $storeId): array;

    public function supportsStoreScope(): bool;

    public function resolveEntityId(RequestInterface $request): ?string;

    /** Store view scope of the request; Store::DEFAULT_STORE_ID when none. */
    public function resolveStoreId(RequestInterface $request): int;

    /**
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function load(string $entityId, int $storeId): DataObject;

    public function getTitle(DataObject $entity): ?string;

    /** Storefront URL to land a preview on, or null when the entity has no page of its own. */
    public function getFrontendUrl(DataObject $entity, int $storeId): ?string;

    /** Adapter-level POST pre-processing before sections extract (mirrors the core Save controller). */
    public function preparePost(array $post, DataObject $entity, int $storeId): array;

    /** @return \MageDrop\Magento2\Model\Entity\Section\SectionHandlerInterface[] */
    public function getSections(): array;

    /** @return array<string, Value> */
    public function extract(DataObject $entity, array $post, int $storeId): array;

    /** @return array<string, Value> */
    public function current(DataObject $entity, int $storeId, ?array $fields = null): array;

    public function isScopable(DataObject $entity, string $field): bool;

    public function isOverridden(DataObject $entity, string $field, int $storeId): bool;

    /** @param array<string, Value> $values */
    public function apply(DataObject $entity, array $values, int $storeId): void;

    /** @param array<string, Value> $values */
    public function overlay(DataObject $entity, array $values): void;

    /** @param array<string, Value> $values */
    public function toFormData(array $data, array $values): array;

    /**
     * Persist the entity at the given scope. $appliedFields lists what apply()
     * changed so store-scoped saves can leave every other attribute inheriting.
     *
     * @param string[] $appliedFields
     */
    public function save(DataObject $entity, int $storeId, array $appliedFields = []): void;

    /** Capability description sent to the SaaS on handshake. */
    public function describe(): array;
}
