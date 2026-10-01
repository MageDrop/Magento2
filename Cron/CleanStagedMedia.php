<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Cron;

use MageDrop\Magento2\Model\Media\StagedMediaLog;
use Magento\Catalog\Model\ResourceModel\Product\Gallery as GalleryResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * Daily sweep of media MageDrop kept around (see StagedMediaLog), after the
 * configured retention period (Stores > Configuration > MageDrop > Media Cleanup):
 *  - files staging moved into place for releases that never deployed
 *  - gallery images a deploy removed from a product: once no product links the
 *    gallery row any more, the row is deleted, and the file too when nothing else uses it
 * Anything still (or again, after a rollback) in use is forgotten, never deleted.
 */
class CleanStagedMedia
{
    private const XML_ENABLED = 'magedrop/media/cleanup_enabled';
    private const XML_RETENTION_DAYS = 'magedrop/media/retention_days';
    private const DEFAULT_RETENTION_DAYS = 30;

    public function __construct(
        private StagedMediaLog $log,
        private Filesystem $filesystem,
        private ResourceConnection $resourceConnection,
        private ScopeConfigInterface $scopeConfig,
        private LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_ENABLED)) {
            return;
        }
        $entries = $this->log->all();
        if (!$entries) {
            return;
        }

        $days = (int) $this->scopeConfig->getValue(self::XML_RETENTION_DAYS) ?: self::DEFAULT_RETENTION_DAYS;
        $cutoff = time() - $days * 86400;
        $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $keep = [];
        $deletedFiles = 0;
        $deletedRows = 0;

        foreach ($entries as $entry) {
            if ($entry['at'] > $cutoff) {
                $keep[] = $entry;
                continue;
            }
            try {
                if ($entry['kind'] === 'unlinked_gallery' && !empty($entry['value_id'])) {
                    if ($this->isGalleryRowLinked((int) $entry['value_id'])) {
                        continue; // re-linked by a rollback: in use again
                    }
                    $this->deleteGalleryRow((int) $entry['value_id']);
                    $deletedRows++;
                }
                if ($media->isExist($entry['path']) && !$this->isReferenced($entry['path'])) {
                    $media->delete($entry['path']);
                    $deletedFiles++;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('MageDrop: could not clean up media ' . $entry['path'] . ': ' . $e->getMessage());
                $keep[] = $entry;
            }
        }

        $this->log->replace($keep);

        if ($deletedFiles || $deletedRows) {
            $this->logger->info("MageDrop: media cleanup removed {$deletedFiles} file(s) and {$deletedRows} unlinked gallery row(s).");
        }
    }

    private function isGalleryRowLinked(int $valueId): bool
    {
        $connection = $this->resourceConnection->getConnection();

        return (int) $connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName(GalleryResource::GALLERY_VALUE_TO_ENTITY_TABLE), [new \Zend_Db_Expr('COUNT(*)')])
                ->where('value_id = ?', $valueId)
        ) > 0;
    }

    private function deleteGalleryRow(int $valueId): void
    {
        $connection = $this->resourceConnection->getConnection();
        // Store values and entity links cascade on the gallery's foreign keys
        $connection->delete($this->resourceConnection->getTableName(GalleryResource::GALLERY_TABLE), ['value_id = ?' => $valueId]);
    }

    /**
     * Is the file (path relative to pub/media) used by any product gallery row or category image?
     */
    private function isReferenced(string $mediaPath): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $mediaPath = ltrim($mediaPath, '/');

        // Product gallery rows store the path below catalog/product, e.g. "/a/b/file.jpg"
        if (str_starts_with($mediaPath, 'catalog/product/')) {
            $value = '/' . substr($mediaPath, strlen('catalog/product/'));
            $gallery = $connection->fetchOne(
                $connection->select()
                    ->from($this->resourceConnection->getTableName(GalleryResource::GALLERY_TABLE), [new \Zend_Db_Expr('COUNT(*)')])
                    ->where('value = ?', $value)
            );
            if ((int) $gallery > 0) {
                return true;
            }
        }

        // Category image attributes store "/media/catalog/category/file.jpg" or a bare filename
        $basename = basename($mediaPath);
        $category = $connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('catalog_category_entity_varchar'), [new \Zend_Db_Expr('COUNT(*)')])
                ->where('value IN (?)', [$basename, $mediaPath, '/' . $mediaPath])
                ->orWhere('value LIKE ?', '%/' . $mediaPath)
        );

        return (int) $category > 0;
    }
}
