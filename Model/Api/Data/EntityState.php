<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Api\Data;

use MageDrop\Magento2\Api\Data\EntityStateInterface;

class EntityState implements EntityStateInterface
{
    /**
     * @param \MageDrop\Magento2\Api\Data\ChangeInterface[] $fields
     * @param string[] $overriddenFields
     */
    public function __construct(
        private string $entityType = '',
        private string $entityId = '',
        private int $storeId = 0,
        private ?string $title = null,
        private array $fields = [],
        private array $overriddenFields = [],
        private array $scopableFields = []
    ) {
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public function getStoreId(): int
    {
        return $this->storeId;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getFields(): array
    {
        return $this->fields;
    }

    public function getOverriddenFields(): array
    {
        return $this->overriddenFields;
    }

    public function getScopableFields(): array
    {
        return $this->scopableFields;
    }
}
