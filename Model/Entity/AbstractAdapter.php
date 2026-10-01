<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity;

use MageDrop\Magento2\Model\Entity\Section\SectionHandlerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Store\Model\Store;

abstract class AbstractAdapter implements AdapterInterface
{
    /**
     * @param SectionHandlerInterface[] $sections
     */
    public function __construct(
        private string $code,
        private string $label,
        private string $pluralLabel,
        private string $idParam,
        private string $editRoute,
        private array $sections = [],
        private bool $supportsStoreScope = false,
        private string $formIdKey = ''
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getPluralLabel(): string
    {
        return $this->pluralLabel;
    }

    public function getIdParam(): string
    {
        return $this->idParam;
    }

    public function getFormIdKey(): string
    {
        return $this->formIdKey !== '' ? $this->formIdKey : $this->idParam;
    }

    public function getEditRoute(): string
    {
        return $this->editRoute;
    }

    public function getEditParams(string $entityId, int $storeId): array
    {
        $params = [$this->idParam => $entityId];
        if ($this->supportsStoreScope && $storeId !== Store::DEFAULT_STORE_ID) {
            $params['store'] = $storeId;
        }

        return $params;
    }

    public function supportsStoreScope(): bool
    {
        return $this->supportsStoreScope;
    }

    public function resolveEntityId(RequestInterface $request): ?string
    {
        $id = $request->getParam($this->idParam);

        return $id !== null && $id !== '' ? (string) $id : null;
    }

    public function resolveStoreId(RequestInterface $request): int
    {
        if (!$this->supportsStoreScope) {
            return Store::DEFAULT_STORE_ID;
        }
        $storeId = (int) $request->getParam('store', 0);

        return $storeId ?: (int) $request->getParam('store_id', Store::DEFAULT_STORE_ID);
    }

    public function preparePost(array $post, DataObject $entity, int $storeId): array
    {
        return $post;
    }

    public function getFrontendUrl(DataObject $entity, int $storeId): ?string
    {
        return null;
    }

    public function getSections(): array
    {
        return $this->sections;
    }

    public function extract(DataObject $entity, array $post, int $storeId): array
    {
        $post = $this->preparePost($post, $entity, $storeId);
        $values = [];
        foreach ($this->sections as $section) {
            foreach ($section->extract($post, $entity, $storeId) as $field => $value) {
                $values[$field] = $value;
            }
        }

        return $values;
    }

    public function current(DataObject $entity, int $storeId, ?array $fields = null): array
    {
        $values = [];
        foreach ($this->sections as $section) {
            foreach ($section->current($entity, $storeId, $fields) as $field => $value) {
                if (!isset($values[$field])) {
                    $values[$field] = $value;
                }
            }
        }

        return $values;
    }

    public function isScopable(DataObject $entity, string $field): bool
    {
        if (!$this->supportsStoreScope) {
            return false;
        }
        $section = $this->sectionFor($field, $entity);

        return $section ? $section->isScopable($entity, $field) : false;
    }

    public function isOverridden(DataObject $entity, string $field, int $storeId): bool
    {
        if (!$this->supportsStoreScope || $storeId === Store::DEFAULT_STORE_ID) {
            return true;
        }
        $section = $this->sectionFor($field, $entity);

        return $section ? $section->isOverridden($entity, $field, $storeId) : true;
    }

    public function apply(DataObject $entity, array $values, int $storeId): void
    {
        foreach ($this->partition($values, $entity) as [$section, $sectionValues]) {
            $section->apply($entity, $sectionValues, $storeId);
        }
    }

    public function overlay(DataObject $entity, array $values): void
    {
        foreach ($this->partition($values, $entity) as [$section, $sectionValues]) {
            $section->overlay($entity, $sectionValues);
        }
    }

    public function toFormData(array $data, array $values): array
    {
        // Form data is not an entity; route by the field name using a bare DataObject
        $probe = new DataObject();
        foreach ($this->sections as $section) {
            $mine = array_filter(
                $values,
                fn (string $field) => $section->handles($field, $probe),
                ARRAY_FILTER_USE_KEY
            );
            if ($mine) {
                $data = $section->toFormData($data, $mine);
            }
        }

        return $data;
    }

    public function describe(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'plural' => $this->pluralLabel,
            'supports_scope' => $this->supportsStoreScope,
            'edit_route' => $this->editRoute,
            'id_param' => $this->idParam,
        ];
    }

    protected function sectionFor(string $field, DataObject $entity): ?SectionHandlerInterface
    {
        foreach ($this->sections as $section) {
            if ($section->handles($field, $entity)) {
                return $section;
            }
        }

        return null;
    }

    /**
     * Group values by the section that owns each field; unknown fields are rejected.
     *
     * @param array<string, Value> $values
     * @return array<int, array{0: SectionHandlerInterface, 1: array<string, Value>}>
     */
    protected function partition(array $values, DataObject $entity): array
    {
        $groups = [];
        foreach ($values as $field => $value) {
            $matched = false;
            foreach ($this->sections as $index => $section) {
                if ($section->handles($field, $entity)) {
                    $groups[$index][0] = $section;
                    $groups[$index][1][$field] = $value;
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                throw new \InvalidArgumentException(
                    sprintf('Field "%s" is not stageable on %s.', $field, $this->label)
                );
            }
        }

        return array_values($groups);
    }
}
