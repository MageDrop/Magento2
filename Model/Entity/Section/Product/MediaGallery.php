<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Section\Product;

use MageDrop\Magento2\Model\Entity\Section\SectionHandlerInterface;
use MageDrop\Magento2\Model\Entity\Value;
use MageDrop\Magento2\Model\Media\StagedMediaLog;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Attribute\ScopeOverriddenValue;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Catalog\Model\ResourceModel\Product\Gallery as GalleryResource;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\DataObject;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Filesystem;
use Magento\MediaStorage\Helper\File\Storage\Database as FileStorageDb;
use Magento\MediaStorage\Model\File\Uploader;
use Magento\Store\Model\Store;

/**
 * Product media gallery as one normalised list:
 *   [{value_id, file, media_type, label, position, disabled, roles: [image, small_image, ...], url}]
 *
 * Stage time: files the admin just uploaded (".tmp" suffix, living in
 * tmp/catalog/product) are moved to their final catalog/product/<dispersion>
 * path exactly as Gallery\CreateHandler::moveImageFromTmp would, so the staged
 * value references a definitive file and preview can render it.
 *
 * Apply time: entries without a value_id are inserted directly into the
 * gallery tables (the core handler would otherwise try to move them from tmp
 * again and fail) and removed images are unlinked from the product directly
 * (never via the core "removed" flag, which deletes the file and would make
 * rollback impossible); the normal product save then handles labels,
 * positions and roles at the requested store scope.
 */
class MediaGallery implements SectionHandlerInterface
{
    public const FIELD = 'media_gallery';
    public const VIDEO_KEYS = ['video_url', 'video_title', 'video_description', 'video_provider', 'video_metadata'];

    /**
     * @param string[] $extraKeys additional per-image keys a third-party module stores on
     *                            gallery rows (e.g. a per-image option mapping); carried through
     *                            staging, apply and overlay untouched. Configure via di.xml.
     */
    public function __construct(
        private MediaConfig $mediaConfig,
        private Filesystem $filesystem,
        private FileStorageDb $fileStorageDb,
        private GalleryResource $galleryResource,
        private MetadataPool $metadataPool,
        private StagedMediaLog $stagedMediaLog,
        private ScopeOverriddenValue $scopeOverriddenValue,
        private array $extraKeys = []
    ) {
        // External video entries carry their metadata on the gallery row
        $this->extraKeys = array_values(array_unique(array_merge(self::VIDEO_KEYS, $this->extraKeys)));
    }

    public function extract(array $post, DataObject $entity, int $storeId): array
    {
        $images = $post[self::FIELD]['images'] ?? null;
        if (!is_array($images)) {
            return [];
        }

        $roleFiles = [];
        foreach ($this->roles() as $role) {
            $value = $post[$role] ?? null;
            if (is_string($value) && $value !== '' && $value !== 'no_selection') {
                $roleFiles[$role] = $value;
            }
        }

        $renamed = [];
        $entries = [];
        foreach ($images as $image) {
            if (!is_array($image) || empty($image['file'])) {
                continue;
            }
            if (!empty($image['removed'])) {
                continue;
            }
            $file = (string) $image['file'];
            if ($this->isTmpFile($file)) {
                $final = $this->moveImageFromTmp($file);
                $renamed[$file] = $final;
                $file = $final;
            }
            $entries[] = $this->entry(
                !empty($image['value_id']) ? (int) $image['value_id'] : null,
                $file,
                (string) ($image['media_type'] ?? 'image'),
                (string) ($image['label'] ?? ''),
                (int) ($image['position'] ?? 0),
                !empty($image['disabled']) ? 1 : 0,
                $this->extras($image)
            );
        }

        foreach ($roleFiles as $role => $file) {
            $file = $renamed[$file] ?? $file;
            foreach ($entries as &$entry) {
                if ($entry['file'] === $file) {
                    $entry['roles'][] = $role;
                }
            }
            unset($entry);
        }

        return [self::FIELD => Value::json($this->finalise($entries))];
    }

    public function current(DataObject $entity, int $storeId, ?array $fields = null): array
    {
        if ($fields !== null && !in_array(self::FIELD, $fields, true)) {
            return [];
        }
        if (!$entity instanceof Product) {
            return [];
        }

        return [self::FIELD => Value::json($this->currentEntries($entity))];
    }

    public function handles(string $field, DataObject $entity): bool
    {
        return $field === self::FIELD;
    }

    /**
     * The set of images is global; only per-image label/position/hidden and the roles
     * have store values, and overlay() merges those itself.
     */
    public function isScopable(DataObject $entity, string $field): bool
    {
        return false;
    }

    public function isOverridden(DataObject $entity, string $field, int $storeId): bool
    {
        if ($storeId === Store::DEFAULT_STORE_ID || !$entity->getId()) {
            return true;
        }
        $connection = $this->galleryResource->getConnection();
        $select = $connection->select()
            ->from($this->galleryResource->getTable(GalleryResource::GALLERY_VALUE_TABLE), [new \Zend_Db_Expr('COUNT(*)')])
            ->where($this->linkField() . ' = ?', $this->linkValue($entity))
            ->where('store_id = ?', $storeId);

        return (int) $connection->fetchOne($select) > 0;
    }

    public function apply(DataObject $entity, array $values, int $storeId): void
    {
        if (!isset($values[self::FIELD]) || !$entity instanceof Product) {
            return;
        }
        $value = $values[self::FIELD];
        if ($value->isInherit()) {
            throw new \InvalidArgumentException('The media gallery cannot inherit; stage the default-scope gallery instead.');
        }

        $staged = is_array($value->value) ? $value->value : [];
        $current = $this->currentEntries($entity);
        $currentByValueId = [];
        $currentByFile = [];
        foreach ($current as $entry) {
            if ($entry['value_id']) {
                $currentByValueId[$entry['value_id']] = $entry;
            }
            $currentByFile[$entry['file']] = $entry;
        }

        $attributeId = (int) $entity->getResource()->getAttribute(self::FIELD)->getAttributeId();
        $linkValue = $this->linkValue($entity);

        $images = [];
        $roles = [];
        $seen = [];
        foreach ($staged as $entry) {
            if (!is_array($entry) || empty($entry['file'])) {
                continue;
            }
            $file = (string) $entry['file'];
            $valueId = !empty($entry['value_id']) && isset($currentByValueId[(int) $entry['value_id']])
                ? (int) $entry['value_id']
                : ($currentByFile[$file]['value_id'] ?? null);

            if (!$valueId) {
                // An image unlinked by an earlier deploy (rollback) keeps its gallery row: re-link it.
                // Otherwise it is a brand-new file (moved to its final location at staging time).
                $valueId = $this->unlinkedGalleryRow((int) ($entry['value_id'] ?? 0), $file)
                    ?? (int) $this->galleryResource->insertGallery([
                        'attribute_id' => $attributeId,
                        'media_type' => (string) ($entry['media_type'] ?? 'image'),
                        'value' => $file,
                        'disabled' => 0,
                    ]);
                $this->galleryResource->bindValueToEntity($valueId, $linkValue);
                $this->galleryResource->insertGalleryValueInStore([
                    'value_id' => $valueId,
                    'store_id' => Store::DEFAULT_STORE_ID,
                    $this->linkField() => $linkValue,
                    'label' => null,
                    'position' => (int) ($entry['position'] ?? 0),
                    'disabled' => 0,
                ]);
            }

            $seen[$valueId] = true;
            $images[$valueId] = [
                'value_id' => $valueId,
                'file' => $file,
                'media_type' => (string) ($entry['media_type'] ?? 'image'),
                'label' => (string) ($entry['label'] ?? ''),
                'position' => (int) ($entry['position'] ?? 0),
                'disabled' => !empty($entry['disabled']) ? 1 : 0,
                'removed' => '',
            ] + $this->extras($entry);
            foreach ((array) ($entry['roles'] ?? []) as $role) {
                if (in_array($role, $this->roles(), true)) {
                    $roles[$role] = $file;
                }
            }
        }

        // Unlink removed images ourselves: flagging them "removed" makes the core handler delete
        // the file when no other product uses it, and rollback could never bring it back
        foreach (array_keys($currentByValueId) as $valueId) {
            if (!isset($seen[$valueId])) {
                $this->unlinkImage((int) $valueId, $linkValue);
            }
        }

        $entity->setData(self::FIELD, ['images' => array_values($images)]);
        foreach ($this->roles() as $role) {
            $entity->setData($role, $roles[$role] ?? 'no_selection');
        }
        $entity->unsetData('media_gallery_images');
    }

    public function overlay(DataObject $entity, array $values): void
    {
        if (!isset($values[self::FIELD]) || $values[self::FIELD]->isInherit()) {
            return;
        }
        $staged = is_array($values[self::FIELD]->value) ? $values[self::FIELD]->value : [];

        $storeId = (int) $entity->getStoreId();
        $storeValues = $storeId !== Store::DEFAULT_STORE_ID ? $this->storeImageValues($entity) : [];

        $images = [];
        $roles = [];
        $synthetic = -1;
        foreach ($staged as $entry) {
            if (!is_array($entry) || empty($entry['file'])) {
                continue;
            }
            $valueId = !empty($entry['value_id']) ? (int) $entry['value_id'] : $synthetic--;
            // Store-view label/position/hidden rows win over a default-scope change, as after deploy
            $images[$valueId] = ($storeValues[$valueId] ?? []) + [
                'value_id' => $valueId,
                'file' => (string) $entry['file'],
                'media_type' => (string) ($entry['media_type'] ?? 'image'),
                'label' => (string) ($entry['label'] ?? ''),
                'position' => (int) ($entry['position'] ?? 0),
                'disabled' => !empty($entry['disabled']) ? 1 : 0,
            ] + $this->extras($entry);
            foreach ((array) ($entry['roles'] ?? []) as $role) {
                if (in_array($role, $this->roles(), true)) {
                    $roles[$role] = (string) $entry['file'];
                    $roles[$role . '_label'] = (string) ($entry['label'] ?? '');
                }
            }
        }

        // The frontend renders gallery images in array order, as loaded (sorted by position)
        uasort($images, fn (array $a, array $b) => $a['position'] <=> $b['position']);

        $entity->setData(self::FIELD, ['images' => $images]);
        $entity->unsetData('media_gallery_images');
        foreach ($this->roles() as $role) {
            if ($storeId !== Store::DEFAULT_STORE_ID && $entity instanceof Product
                && $this->scopeOverriddenValue->containsValue(ProductInterface::class, $entity, $role, $storeId)
            ) {
                continue; // the store view keeps its own role image
            }
            $entity->setData($role, $roles[$role] ?? 'no_selection');
            $entity->setData($role . '_label', $roles[$role . '_label'] ?? null);
        }
    }

    public function toFormData(array $data, array $values): array
    {
        if (!isset($values[self::FIELD]) || $values[self::FIELD]->isInherit()) {
            return $data;
        }
        $staged = is_array($values[self::FIELD]->value) ? $values[self::FIELD]->value : [];

        $images = [];
        $roles = [];
        $i = 0;
        foreach ($staged as $entry) {
            if (!is_array($entry) || empty($entry['file'])) {
                continue;
            }
            $key = !empty($entry['value_id']) ? (string) $entry['value_id'] : 'magedrop_' . $i++;
            $images[$key] = [
                'value_id' => $entry['value_id'] ?? null,
                'file' => (string) $entry['file'],
                'media_type' => (string) ($entry['media_type'] ?? 'image'),
                'label' => (string) ($entry['label'] ?? ''),
                'position' => (string) ((int) ($entry['position'] ?? 0)),
                'disabled' => !empty($entry['disabled']) ? '1' : '0',
                'url' => $this->mediaConfig->getMediaUrl((string) $entry['file']),
            ] + $this->extras($entry);
            foreach ((array) ($entry['roles'] ?? []) as $role) {
                if (in_array($role, $this->roles(), true)) {
                    $roles[$role] = (string) $entry['file'];
                }
            }
        }

        $data[self::FIELD] = ['images' => $images];
        foreach ($this->roles() as $role) {
            $data[$role] = $roles[$role] ?? 'no_selection';
        }

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * Per-image values the store view has its own gallery rows for, keyed by value_id.
     * Read from the table: a store row equal to the default still wins after deploy.
     *
     * @return array<int, array<string, mixed>>
     */
    private function storeImageValues(DataObject $entity): array
    {
        if (!$entity->getId()) {
            return [];
        }
        $connection = $this->galleryResource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->galleryResource->getTable(GalleryResource::GALLERY_VALUE_TABLE), ['value_id', 'label', 'position', 'disabled'])
                ->where($this->linkField() . ' = ?', $this->linkValue($entity))
                ->where('store_id = ?', (int) $entity->getStoreId())
        );

        $overrides = [];
        foreach ($rows as $row) {
            $values = [];
            if ($row['label'] !== null) {
                $values['label'] = (string) $row['label'];
            }
            if ($row['position'] !== null) {
                $values['position'] = (int) $row['position'];
            }
            if ($row['disabled'] !== null) {
                $values['disabled'] = (int) $row['disabled'];
            }
            $overrides[(int) $row['value_id']] = $values;
        }

        return $overrides;
    }

    /**
     * Image role attributes (frontend input "media_image"), including custom ones.
     *
     * @return string[]
     */
    public function roles(): array
    {
        return $this->mediaConfig->getMediaAttributeCodes();
    }

    private function currentEntries(Product $product): array
    {
        $gallery = $product->getData(self::FIELD);
        $images = is_array($gallery) && isset($gallery['images']) && is_array($gallery['images']) ? $gallery['images'] : [];

        $roleFiles = [];
        foreach ($this->roles() as $role) {
            $value = $product->getData($role);
            if (is_string($value) && $value !== '' && $value !== 'no_selection') {
                $roleFiles[$value][] = $role;
            }
        }

        $entries = [];
        foreach ($images as $image) {
            if (!is_array($image) || empty($image['file']) || !empty($image['removed'])) {
                continue;
            }
            $entry = $this->entry(
                !empty($image['value_id']) ? (int) $image['value_id'] : null,
                (string) $image['file'],
                (string) ($image['media_type'] ?? 'image'),
                (string) ($image['label'] ?? ''),
                (int) ($image['position'] ?? 0),
                !empty($image['disabled']) ? 1 : 0,
                $this->extras($image)
            );
            $entry['roles'] = $roleFiles[$entry['file']] ?? [];
            $entries[] = $entry;
        }

        return $this->finalise($entries);
    }

    private function entry(?int $valueId, string $file, string $mediaType, string $label, int $position, int $disabled, array $extras = []): array
    {
        $file = '/' . ltrim(str_replace('\\', '/', $file), '/');

        return [
            'value_id' => $valueId,
            'file' => $file,
            'media_type' => $mediaType ?: 'image',
            'label' => $label,
            'position' => $position,
            'disabled' => $disabled,
            'roles' => [],
            'url' => $this->mediaConfig->getMediaUrl($file),
        ] + $extras;
    }

    /**
     * Third-party per-image keys, normalised to strings (null when absent).
     */
    private function extras(array $image): array
    {
        $out = [];
        foreach ($this->extraKeys as $key) {
            $value = $image[$key] ?? null;
            $out[$key] = $value === null || $value === '' ? null : (is_scalar($value) ? (string) $value : json_encode($value));
        }

        return $out;
    }

    /**
     * Stable ordering so equal galleries encode identically.
     */
    private function finalise(array $entries): array
    {
        foreach ($entries as &$entry) {
            $roles = array_values(array_unique($entry['roles']));
            sort($roles);
            $entry['roles'] = $roles;
        }
        unset($entry);
        usort($entries, fn ($a, $b) => [$a['position'], $a['file']] <=> [$b['position'], $b['file']]);

        return array_values($entries);
    }

    private function isTmpFile(string $file): bool
    {
        return str_ends_with($file, '.tmp');
    }

    /**
     * Gallery\CreateHandler::moveImageFromTmp, minus the product save around it.
     */
    private function moveImageFromTmp(string $file): string
    {
        $file = substr($file, 0, -4); // strip ".tmp"
        $file = '/' . ltrim(str_replace('\\', '/', $file), '/');

        $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);

        if ($this->fileStorageDb->checkDbUsage()) {
            $destination = $this->fileStorageDb->getUniqueFilename($this->mediaConfig->getBaseMediaUrlAddition(), $file);
            $this->fileStorageDb->renameFile(
                $this->mediaConfig->getTmpMediaShortUrl($file),
                $this->mediaConfig->getMediaShortUrl($destination)
            );
            $mediaDirectory->delete($this->mediaConfig->getTmpMediaPath($file));
            $mediaDirectory->delete($this->mediaConfig->getMediaPath($destination));
        } else {
            $absolute = $mediaDirectory->getAbsolutePath($this->mediaConfig->getMediaPath($file));
            $destination = dirname($file) . '/' . Uploader::getNewFileName($absolute);
            $mediaDirectory->renameFile(
                $this->mediaConfig->getTmpMediaPath($file),
                $this->mediaConfig->getMediaPath($destination)
            );
        }

        $destination = '/' . ltrim(str_replace('\\', '/', $destination), '/');
        $this->stagedMediaLog->record($this->mediaConfig->getMediaPath($destination), 'product_gallery');

        return $destination;
    }

    private function unlinkedGalleryRow(int $valueId, string $file): ?int
    {
        if ($valueId <= 0) {
            return null;
        }
        $connection = $this->galleryResource->getConnection();
        $found = $connection->fetchOne(
            $connection->select()
                ->from($this->galleryResource->getMainTable(), ['value_id'])
                ->where('value_id = ?', $valueId)
                ->where('value = ?', $file)
        );

        return $found !== false ? (int) $found : null;
    }

    private function unlinkImage(int $valueId, int $linkValue): void
    {
        $connection = $this->galleryResource->getConnection();
        $where = ['value_id = ?' => $valueId, $this->linkField() . ' = ?' => $linkValue];
        $connection->delete($this->galleryResource->getTable(GalleryResource::GALLERY_VALUE_TABLE), $where);
        $connection->delete($this->galleryResource->getTable(GalleryResource::GALLERY_VALUE_TO_ENTITY_TABLE), $where);
    }

    private function linkField(): string
    {
        return $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
    }

    private function linkValue(DataObject $entity): int
    {
        return (int) ($entity->getData($this->linkField()) ?: $entity->getId());
    }
}
