<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Section;

use MageDrop\Magento2\Model\Entity\Value;
use Magento\Catalog\Model\Attribute\ScopeOverriddenValue;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\DataObject;
use Magento\Store\Model\Store;

/**
 * Generic scalar / EAV attribute handler.
 *
 * Configured per adapter via di.xml virtual types:
 *  - eav              : true for EAV entities (category, product) — only real attributes are staged
 *  - entityInterface  : the Api\Data interface used by ScopeOverriddenValue (EAV only)
 *  - ignoredFields    : form keys that are never content (ids, timestamps, layout, flags...)
 *  - ignoredPrefixes  : form key prefixes to skip
 *  - skipBackends     : attribute backend classes owned by other sections (e.g. image backend)
 *  - boolFields       : keys posted as "true"/"false" strings by the admin form
 */
class Attributes implements SectionHandlerInterface
{
    private const LAYOUT_NO_UPDATE = '__no_update__';

    /**
     * @param string[] $ignoredFields
     * @param string[] $ignoredPrefixes
     * @param string[] $skipBackends
     * @param string[] $boolFields
     */
    public function __construct(
        private ScopeOverriddenValue $scopeOverriddenValue,
        private bool $eav = false,
        private string $entityInterface = '',
        private array $ignoredFields = [],
        private array $ignoredPrefixes = ['use_default_', 'use_config_', 'magedrop_'],
        private array $skipBackends = [],
        private array $boolFields = []
    ) {
    }

    public function extract(array $post, DataObject $entity, int $storeId): array
    {
        $useDefault = is_array($post['use_default'] ?? null) ? $post['use_default'] : [];
        $values = [];

        foreach ($post as $field => $raw) {
            if (!$this->handles((string) $field, $entity)) {
                continue;
            }

            // "Use Default Value" ticked at store scope → inherit (the disabled input is still posted)
            if ($storeId !== Store::DEFAULT_STORE_ID && !empty($useDefault[$field])) {
                $attribute = $this->getAttribute($entity, (string) $field);
                if ($attribute && $this->isGlobal($attribute)) {
                    continue;
                }
                $values[$field] = Value::inherit();
                continue;
            }

            if (!is_scalar($raw) && $raw !== null) {
                continue;
            }

            if (in_array($field, $this->boolFields, true) && is_string($raw)) {
                if ($raw === 'true') {
                    $raw = true;
                } elseif ($raw === 'false') {
                    $raw = false;
                }
            }

            // Layout update selector's "no change" sentinel, stripped by the core save controllers
            if ($raw === self::LAYOUT_NO_UPDATE) {
                continue;
            }

            $value = Value::text($raw);

            // Attributes that never had a value (e.g. added by an extension later) post their default
            if ($this->eav && $entity->getData($field) === null && $this->isAttributeDefault($entity, (string) $field, $value)) {
                continue;
            }

            $values[$field] = $value;
        }

        return $values;
    }

    public function current(DataObject $entity, int $storeId, ?array $fields = null): array
    {
        if ($fields === null) {
            $fields = $this->allFields($entity);
        }

        $values = [];
        foreach ($fields as $field) {
            if (!$this->handles($field, $entity)) {
                continue;
            }
            $raw = $entity->getData($field);
            if (!is_scalar($raw) && $raw !== null) {
                continue;
            }
            $values[$field] = Value::text($raw);
        }

        return $values;
    }

    public function handles(string $field, DataObject $entity): bool
    {
        if ($field === '' || $field[0] === '_' || in_array($field, $this->ignoredFields, true)) {
            return false;
        }
        foreach ($this->ignoredPrefixes as $prefix) {
            if (str_starts_with($field, $prefix)) {
                return false;
            }
        }
        if (!$this->eav) {
            return true;
        }

        $attribute = $this->getAttribute($entity, $field);
        if (!$attribute) {
            return false;
        }
        foreach ($this->skipBackends as $backendClass) {
            if ($attribute->getBackend() instanceof $backendClass) {
                return false;
            }
        }

        return true;
    }

    public function isScopable(DataObject $entity, string $field): bool
    {
        if (!$this->eav) {
            return false;
        }
        $attribute = $this->getAttribute($entity, $field);

        return $attribute !== null && !$this->isGlobal($attribute);
    }

    public function isOverridden(DataObject $entity, string $field, int $storeId): bool
    {
        if (!$this->eav || $storeId === Store::DEFAULT_STORE_ID) {
            return true;
        }
        $attribute = $this->getAttribute($entity, $field);
        if (!$attribute || $this->isGlobal($attribute)) {
            return true;
        }
        if (!$this->entityInterface || !$entity instanceof \Magento\Framework\Model\AbstractModel) {
            return true;
        }

        return $this->scopeOverriddenValue->containsValue($this->entityInterface, $entity, $field, $storeId);
    }

    public function apply(DataObject $entity, array $values, int $storeId): void
    {
        foreach ($values as $field => $value) {
            if ($value->isInherit()) {
                if ($storeId === Store::DEFAULT_STORE_ID) {
                    throw new \InvalidArgumentException(
                        sprintf('Field "%s" cannot inherit at default scope.', $field)
                    );
                }
                $attribute = $this->getAttribute($entity, $field);
                if ($attribute && $this->isGlobal($attribute)) {
                    throw new \InvalidArgumentException(
                        sprintf('Attribute "%s" is global and cannot use a default value.', $field)
                    );
                }
                // An empty value at store scope deletes the store row (Magento's own "Use Default")
                $entity->setData($field, null);
                continue;
            }
            $entity->setData($field, $value->forModel());
        }
    }

    public function overlay(DataObject $entity, array $values): void
    {
        foreach ($values as $field => $value) {
            if ($value->isInherit()) {
                continue;
            }
            $entity->setData($field, $value->forModel());
        }
    }

    public function toFormData(array $data, array $values): array
    {
        foreach ($values as $field => $value) {
            if ($value->isInherit()) {
                $data['use_default'][$field] = 1;
                continue;
            }
            $data[$field] = $value->forModel();
        }

        return $data;
    }

    /**
     * @return string[]
     */
    private function allFields(DataObject $entity): array
    {
        if ($this->eav && method_exists($entity, 'getAttributes')) {
            return array_keys($entity->getAttributes());
        }

        return array_keys($entity->getData());
    }

    private function getAttribute(DataObject $entity, string $field): ?AbstractAttribute
    {
        if (!$this->eav || !method_exists($entity, 'getResource')) {
            return null;
        }
        $resource = $entity->getResource();
        if (!method_exists($resource, 'getAttribute')) {
            return null;
        }
        try {
            $attribute = $resource->getAttribute($field);
        } catch (\Throwable) {
            return null;
        }

        return $attribute instanceof AbstractAttribute ? $attribute : null;
    }

    private function isAttributeDefault(DataObject $entity, string $field, Value $value): bool
    {
        $attribute = $this->getAttribute($entity, $field);
        $default = $attribute?->getDefaultValue();
        if ($default === null || $default === '') {
            return false;
        }

        return Value::text($default)->equals($value);
    }

    private function isGlobal(AbstractAttribute $attribute): bool
    {
        return method_exists($attribute, 'isScopeGlobal') ? (bool) $attribute->isScopeGlobal() : false;
    }
}
