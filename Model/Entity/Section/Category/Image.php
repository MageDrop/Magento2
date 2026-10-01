<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Section\Category;

use MageDrop\Magento2\Model\Entity\Section\SectionHandlerInterface;
use MageDrop\Magento2\Model\Entity\Value;
use MageDrop\Magento2\Model\Media\StagedMediaLog;
use Magento\Catalog\Api\Data\CategoryAttributeInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Attribute\ScopeOverriddenValue;
use Magento\Catalog\Model\Category\Attribute\Backend\Image as ImageBackend;
use Magento\Catalog\Model\Category\FileInfo;
use Magento\Catalog\Model\ImageUploader;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\DataObject;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Category image-backed attributes (image, thumbnail and any custom attribute
 * using the category Image backend).
 *
 * At stage time a freshly uploaded tmp file is moved to its final
 * catalog/category location (exactly what Backend\Image::beforeSave would do on
 * a real save) so the staged value is the definitive media path. Apply is then
 * a plain setData and preview can render the file immediately.
 */
class Image implements SectionHandlerInterface
{
    /** @var string[]|null */
    private ?array $imageAttributes = null;

    public function __construct(
        private EavConfig $eavConfig,
        private ImageUploader $imageUploader,
        private StoreManagerInterface $storeManager,
        private ScopeOverriddenValue $scopeOverriddenValue,
        private StagedMediaLog $stagedMediaLog
    ) {
    }

    public function extract(array $post, DataObject $entity, int $storeId): array
    {
        $useDefault = is_array($post['use_default'] ?? null) ? $post['use_default'] : [];
        $values = [];

        foreach ($this->imageAttributes() as $code) {
            if ($storeId !== Store::DEFAULT_STORE_ID && !empty($useDefault[$code])) {
                $values[$code] = Value::inherit();
                continue;
            }

            $raw = $post[$code] ?? null;

            // Absent from POST means the image was removed (Category\Save::imagePreprocessing)
            if ($raw === null || $raw === '' || (is_array($raw) && !empty($raw['delete']))) {
                $values[$code] = Value::image('');
                continue;
            }

            if (is_array($raw)) {
                $entry = $raw[0] ?? null;
                if (!is_array($entry) || empty($entry['name'])) {
                    $values[$code] = Value::image('');
                    continue;
                }
                if (!empty($entry['tmp_name'])) {
                    $relative = $this->imageUploader->moveFileFromTmp((string) $entry['name'], true);
                    $this->stagedMediaLog->record((string) $relative, 'category_image');
                    $path = '/' . $this->baseMediaDir() . '/' . ltrim((string) $relative, '/');
                } elseif (!empty($entry['url'])) {
                    $path = (string) parse_url((string) $entry['url'], PHP_URL_PATH);
                } else {
                    $path = $this->canonicalPath((string) $entry['name']);
                }
                $values[$code] = Value::image($path, $this->urlFor($path));
                continue;
            }

            if (is_string($raw)) {
                $path = $this->canonicalPath($raw);
                $values[$code] = Value::image($path, $this->urlFor($path));
            }
        }

        return $values;
    }

    public function current(DataObject $entity, int $storeId, ?array $fields = null): array
    {
        $codes = $fields === null
            ? $this->imageAttributes()
            : array_values(array_intersect($fields, $this->imageAttributes()));

        $values = [];
        foreach ($codes as $code) {
            $raw = $entity->getData($code);
            $path = is_string($raw) && $raw !== '' ? $this->canonicalPath($raw) : '';
            $values[$code] = Value::image($path, $path !== '' ? $this->urlFor($path) : null);
        }

        return $values;
    }

    public function handles(string $field, DataObject $entity): bool
    {
        return in_array($field, $this->imageAttributes(), true);
    }

    public function isScopable(DataObject $entity, string $field): bool
    {
        return true;
    }

    public function isOverridden(DataObject $entity, string $field, int $storeId): bool
    {
        if ($storeId === Store::DEFAULT_STORE_ID || !$entity instanceof \Magento\Framework\Model\AbstractModel) {
            return true;
        }

        return $this->scopeOverriddenValue->containsValue(CategoryInterface::class, $entity, $field, $storeId);
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
                $entity->setData($field, null);
                continue;
            }
            // A string passes straight through Backend\Image::beforeSave; '' clears the attribute.
            $entity->setData($field, (string) $value->value);
        }
    }

    public function overlay(DataObject $entity, array $values): void
    {
        foreach ($values as $field => $value) {
            if ($value->isInherit()) {
                continue;
            }
            $entity->setData($field, (string) $value->value !== '' ? (string) $value->value : null);
        }
    }

    public function toFormData(array $data, array $values): array
    {
        foreach ($values as $field => $value) {
            if ($value->isInherit()) {
                $data['use_default'][$field] = 1;
                continue;
            }
            $path = (string) $value->value;
            if ($path === '') {
                unset($data[$field]);
                continue;
            }
            $data[$field] = [[
                'name' => basename($path),
                'url' => $this->urlFor($path),
            ]];
        }

        return $data;
    }

    /**
     * @return string[]
     */
    private function imageAttributes(): array
    {
        if ($this->imageAttributes !== null) {
            return $this->imageAttributes;
        }
        $codes = [];
        $entityType = $this->eavConfig->getEntityType(CategoryAttributeInterface::ENTITY_TYPE_CODE);
        foreach ($entityType->getAttributeCollection() as $attribute) {
            if ($attribute->getBackend() instanceof ImageBackend) {
                $codes[] = $attribute->getAttributeCode();
            }
        }

        return $this->imageAttributes = $codes;
    }

    /**
     * Normalise legacy bare filenames ("x.jpg") to the full media path form
     * Magento 2.4 stores ("/media/catalog/category/x.jpg").
     */
    private function canonicalPath(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (str_starts_with($value, '/')) {
            return $value;
        }
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return (string) parse_url($value, PHP_URL_PATH);
        }

        return '/' . $this->baseMediaDir() . '/' . ltrim(FileInfo::ENTITY_MEDIA_PATH, '/') . '/' . ltrim($value, '/');
    }

    private function baseMediaDir(): string
    {
        return trim((string) $this->storeManager->getStore()->getBaseMediaDir(), '/');
    }

    private function urlFor(string $path): ?string
    {
        if ($path === '') {
            return null;
        }
        $base = rtrim((string) $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/');

        return $base . '/' . ltrim($path, '/');
    }
}
