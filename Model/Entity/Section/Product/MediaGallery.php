<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Section\Product;

use MageDrop\Magento2\Model\Entity\Section\AfterSaveInterface;
use MageDrop\Magento2\Model\Entity\Section\CapturesPreviousInterface;
use MageDrop\Magento2\Model\Entity\Section\LoadsIntoEntityInterface;
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
class MediaGallery implements SectionHandlerInterface, CapturesPreviousInterface, AfterSaveInterface, LoadsIntoEntityInterface
{
    public const FIELD = 'media_gallery';

    /**
     * Keys on entries of a captured store-view previous value (see previous()): whether
     * the store view had its own row for the image, and which roles it overrode with it.
     */
    public const STORE_ROW = 'store_row';
    public const STORE_ROLES = 'store_roles';

    /** @var array<int, int[]> entity id => value ids whose store rows to drop after save */
    private array $dropStoreRows = [];
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
        $useDefault = is_array($post['use_default'] ?? null) ? $post['use_default'] : [];
        foreach ($this->roles() as $role) {
            // "Use Default Value" ticked at a store view: Magento's initialiser blanks the role,
            // but the view still shows (and keeps) the default's image for it
            $value = $storeId !== Store::DEFAULT_STORE_ID && !empty($useDefault[$role])
                ? $entity->getData($role)
                : ($post[$role] ?? null);
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
        return $this->storeRowIds($entity, $storeId) !== [] || $this->overriddenRoles($entity, $storeId) !== [];
    }

    /**
     * At a store view, the gallery's previous state is the resolved list plus which
     * images had their own store row and which roles the view overrode; "inherit" when
     * it had neither. At the default scope the normal capture is exact.
     */
    public function previous(DataObject $entity, string $field, int $storeId): ?Value
    {
        if ($field !== self::FIELD || $storeId === Store::DEFAULT_STORE_ID || !$entity instanceof Product || !$entity->getId()) {
            return null;
        }
        $rowIds = array_flip($this->storeRowIds($entity, $storeId));
        $overridden = $this->overriddenRoles($entity, $storeId);
        if (!$rowIds && !$overridden) {
            return Value::inherit();
        }

        $entries = [];
        foreach ($this->currentEntries($entity) as $entry) {
            $entry[self::STORE_ROW] = $entry['value_id'] !== null && isset($rowIds[$entry['value_id']]);
            $entry[self::STORE_ROLES] = array_values(array_intersect($entry['roles'], $overridden));
            $entries[] = $entry;
        }

        return Value::json($entries);
    }

    public function hasStoreViewState(string $field): bool
    {
        return $field === self::FIELD; // per-image store rows and store-view roles
    }

    public function afterSave(DataObject $entity, int $storeId): void
    {
        $id = (int) $entity->getId();
        $valueIds = $this->dropStoreRows[$id] ?? [];
        unset($this->dropStoreRows[$id]);
        if (!$valueIds || $storeId === Store::DEFAULT_STORE_ID) {
            return;
        }
        // The core gallery handler writes a store row for every image at a store-view
        // save; a restored "no own row" state must not keep them
        $this->galleryResource->getConnection()->delete(
            $this->galleryResource->getTable(GalleryResource::GALLERY_VALUE_TABLE),
            [
                $this->linkField() . ' = ?' => $this->linkValue($entity),
                'store_id = ?' => $storeId,
                'value_id IN (?)' => $valueIds,
            ]
        );
    }

    /**
     * @return int[] value ids the store view has its own gallery row for
     */
    private function storeRowIds(DataObject $entity, int $storeId): array
    {
        $connection = $this->galleryResource->getConnection();

        return array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from($this->galleryResource->getTable(GalleryResource::GALLERY_VALUE_TABLE), ['value_id'])
                ->where($this->linkField() . ' = ?', $this->linkValue($entity))
                ->where('store_id = ?', $storeId)
        ));
    }

    /**
     * @return string[] image roles with a store-view value
     */
    private function overriddenRoles(DataObject $entity, int $storeId): array
    {
        if (!$entity instanceof Product) {
            return [];
        }

        return array_values(array_filter(
            $this->roles(),
            fn (string $role) => $this->scopeOverriddenValue->containsValue(ProductInterface::class, $entity, $role, $storeId)
        ));
    }

    public function apply(DataObject $entity, array $values, int $storeId): void
    {
        if (!isset($values[self::FIELD]) || !$entity instanceof Product) {
            return;
        }
        $value = $values[self::FIELD];
        if ($value->isInherit()) {
            if ($storeId === Store::DEFAULT_STORE_ID) {
                throw new \InvalidArgumentException('The media gallery cannot inherit at the default scope.');
            }
            $this->applyInherit($entity, $storeId);

            return;
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
        foreach ($currentByValueId as $valueId => $entry) {
            if (!isset($seen[$valueId])) {
                $this->unlinkImage((int) $valueId, $linkValue);
                // Kept for rollback; swept by the media cleanup cron after the retention period
                $this->stagedMediaLog->record(
                    $this->mediaConfig->getMediaPath((string) $entry['file']),
                    'unlinked_gallery',
                    ['value_id' => (int) $valueId]
                );
            }
        }

        $entity->setData(self::FIELD, ['images' => array_values($images)]);
        if ($storeId === Store::DEFAULT_STORE_ID) {
            foreach ($this->roles() as $role) {
                $entity->setData($role, $roles[$role] ?? 'no_selection');
            }
        } else {
            $this->applyStoreRoles($entity, $staged, $roles, $storeId);
            if ($this->isCapturedPrevious($staged)) {
                // Rollback of a store-view change: images that had no own store row get none
                $this->dropStoreRows[(int) $entity->getId()] = array_keys(array_filter(
                    $this->storeRowFlags($staged, $images),
                    fn (bool $hadRow) => !$hadRow
                ));
            }
        }
        $entity->unsetData('media_gallery_images');
    }

    /**
     * Store-view roles: a value captured by previous() says exactly which roles the view
     * overrode (others go back to inherited). Otherwise a role becomes a store-view
     * override only when the view already had one or the image actually changes, so an
     * inherited role is never pinned at the store view by an unrelated gallery change.
     *
     * @param array<string, string> $roles role => file from the staged list
     */
    private function applyStoreRoles(Product $entity, array $staged, array $roles, int $storeId): void
    {
        $captured = $this->isCapturedPrevious($staged);
        $keep = [];
        if ($captured) {
            foreach ($staged as $entry) {
                foreach ((array) ($entry[self::STORE_ROLES] ?? []) as $role) {
                    $keep[$role] = true;
                }
            }
        }

        foreach ($this->roles() as $role) {
            $target = $roles[$role] ?? 'no_selection';
            $overridden = $this->scopeOverriddenValue->containsValue(ProductInterface::class, $entity, $role, $storeId);

            if ($captured) {
                // null on a store-loaded model removes the store row (back to the default)
                $entity->setData($role, isset($keep[$role]) ? $target : null);
                continue;
            }
            if (!$overridden && (string) $entity->getData($role) === $target) {
                $entity->setData($role, false); // unchanged and inherited: no store row
                continue;
            }
            $entity->setData($role, $target);
        }
    }

    /** A gallery value produced by previous() (rollback), rather than a staged one. */
    private function isCapturedPrevious(array $staged): bool
    {
        foreach ($staged as $entry) {
            if (is_array($entry) && array_key_exists(self::STORE_ROW, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array> $images value_id => image as applied
     * @return array<int, bool> value_id => had its own store row
     */
    private function storeRowFlags(array $staged, array $images): array
    {
        $byFile = [];
        foreach ($images as $valueId => $image) {
            $byFile[$image['file']] = $valueId;
        }
        $flags = [];
        foreach ($staged as $entry) {
            if (is_array($entry) && isset($byFile[(string) ($entry['file'] ?? '')])) {
                $flags[$byFile[(string) $entry['file']]] = !empty($entry[self::STORE_ROW]);
            }
        }

        return $flags;
    }

    /**
     * "Use Default Value" for the whole gallery at a store view: drop the view's own
     * image rows and role overrides; the images themselves are global and untouched.
     */
    private function applyInherit(Product $entity, int $storeId): void
    {
        $this->galleryResource->getConnection()->delete(
            $this->galleryResource->getTable(GalleryResource::GALLERY_VALUE_TABLE),
            [$this->linkField() . ' = ?' => $this->linkValue($entity), 'store_id = ?' => $storeId]
        );
        foreach ($this->roles() as $role) {
            $entity->setData($role, null);
        }
        // Nothing for the core gallery handlers to write at this scope
        $entity->unsetData(self::FIELD);
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

    /**
     * The admin gallery is not built from the form data but from the product (see
     * loadIntoEntity()), so there is nothing to put into the form here.
     */
    public function toFormData(array $data, array $values): array
    {
        return $data;
    }

    /**
     * "Load from Release" / Quick Preview reload: put the staged gallery on the product the
     * admin edit page is built from, so Magento's own gallery shows it and posts it (images,
     * labels, positions, hidden flags and every image role, custom media attributes included).
     * Images the release adds are presented as fresh uploads: a copy in the tmp media folder
     * under a ".tmp" name, which Save and Save & Stage both move into place.
     *
     * Existing images the release removes stay in the gallery data flagged "removed", so Save and
     * Save & Stage both remove them; GalleryJsonPlugin keeps them out of the visible gallery.
     */
    public function loadIntoEntity(DataObject $product, array $values): void
    {
        if (!$product instanceof Product || !isset($values[self::FIELD]) || $values[self::FIELD]->isInherit()) {
            return;
        }
        $staged = is_array($values[self::FIELD]->value) ? $values[self::FIELD]->value : [];
        $storeId = (int) $product->getStoreId();

        $gallery = $product->getData(self::FIELD);
        $current = [];
        foreach ((is_array($gallery) && is_array($gallery['images'] ?? null) ? $gallery['images'] : []) as $image) {
            if (is_array($image) && !empty($image['value_id'])) {
                $current[(int) $image['value_id']] = $image;
            }
        }
        $storeValues = $storeId !== Store::DEFAULT_STORE_ID ? $this->storeImageValues($product) : [];
        // Staged for this store view itself (e.g. a Quick Preview made at the view): the values are
        // the view's own; otherwise they are the default scope's and a view's own row still wins
        $stagedAtView = !empty(((array) $product->getData(self::SCOPE_OVERRIDES))[self::FIELD]);

        $images = [];
        $roles = [];
        $kept = [];
        $new = 0;
        foreach ($staged as $entry) {
            if (!is_array($entry) || empty($entry['file'])) {
                continue;
            }
            $valueId = !empty($entry['value_id']) ? (int) $entry['value_id'] : 0;
            $imageValues = [
                'label' => (string) ($entry['label'] ?? ''),
                'position' => (string) ((int) ($entry['position'] ?? 0)),
                'disabled' => !empty($entry['disabled']) ? '1' : '0',
            ];
            if ($valueId && isset($current[$valueId])) {
                $kept[$valueId] = true;
                $key = (string) $valueId;
                $image = $current[$valueId];
                $file = (string) $image['file'];
                foreach ($imageValues as $name => $value) {
                    if ($stagedAtView) {
                        $image[$name] = $value;
                        continue;
                    }
                    $image[$name . '_default'] = $value;
                    $image[$name] = array_key_exists($name, $storeValues[$valueId] ?? [])
                        ? (string) $storeValues[$valueId][$name]
                        : $value;
                }
            } else {
                $file = $this->copyToTmp((string) $entry['file']);
                if ($file === null) {
                    continue;
                }
                $key = 'magedrop_new_' . $new++;
                // file_id: the gallery keys its inputs by it, matching this key in the form data
                $image = ['value_id' => '', 'file_id' => $key, 'file' => $file, 'media_type' => (string) ($entry['media_type'] ?? 'image'), 'removed' => '']
                    + $imageValues;
            }
            $images[$key] = $this->extras($entry) + $image;
            foreach ((array) ($entry['roles'] ?? []) as $role) {
                if (in_array($role, $this->roles(), true)) {
                    $roles[$role] = $file;
                    $roles[$role . '_label'] = $imageValues['label'];
                }
            }
        }

        foreach ($current as $valueId => $image) {
            if (!isset($kept[$valueId])) {
                $images[(string) $valueId] = ['removed' => '1'] + $image;
            }
        }

        uasort($images, fn (array $a, array $b) => (int) $a['position'] <=> (int) $b['position']);
        $product->setData(self::FIELD, ['images' => $images, 'values' => []]);
        foreach ($this->roles() as $role) {
            if (!$stagedAtView && $storeId !== Store::DEFAULT_STORE_ID
                && $this->scopeOverriddenValue->containsValue(ProductInterface::class, $product, $role, $storeId)
            ) {
                continue; // default-scope change: the store view keeps its own role image
            }
            $product->setData($role, $roles[$role] ?? 'no_selection');
            $product->setData($role . '_label', $roles[$role . '_label'] ?? null);
        }
    }

    /**
     * Copy an image the release added (already at its final media path) into the tmp media
     * folder, as if it had just been uploaded. Returns the ".tmp" gallery file name.
     */
    private function copyToTmp(string $file): ?string
    {
        $file = '/' . ltrim(str_replace('\\', '/', $file), '/');
        $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $source = $this->mediaConfig->getMediaPath($file);
        $target = $this->mediaConfig->getTmpMediaPath($file);
        if ($this->fileStorageDb->checkDbUsage() && !$mediaDirectory->isFile($source)) {
            $this->fileStorageDb->saveFileToFilesystem($source);
        }
        if (!$mediaDirectory->isFile($source)) {
            return null;
        }
        if (!$mediaDirectory->isFile($target)) {
            $mediaDirectory->copyFile($source, $target);
            if ($this->fileStorageDb->checkDbUsage()) {
                $this->fileStorageDb->saveFile($target);
            }
        }

        return $file . '.tmp';
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
        // Only the default row: store-view rows (own label/position/hidden) stay, unused
        // while unlinked, so re-linking on rollback restores them
        $connection->delete(
            $this->galleryResource->getTable(GalleryResource::GALLERY_VALUE_TABLE),
            $where + ['store_id = ?' => Store::DEFAULT_STORE_ID]
        );
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
