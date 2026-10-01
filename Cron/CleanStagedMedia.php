<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Cron;

use MageDrop\Magento2\Model\Media\StagedMediaLog;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * Staging moves uploaded images into their final media location before any
 * DB row references them. Files from releases that never deployed stay behind;
 * after a grace period, delete the ones nothing references.
 */
class CleanStagedMedia
{
    private const GRACE_DAYS = 14;

    public function __construct(
        private StagedMediaLog $log,
        private Filesystem $filesystem,
        private ResourceConnection $resourceConnection,
        private LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $entries = $this->log->all();
        if (!$entries) {
            return;
        }

        $cutoff = time() - self::GRACE_DAYS * 86400;
        $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $keep = [];
        $deleted = 0;

        foreach ($entries as $entry) {
            if ($entry['at'] > $cutoff) {
                $keep[] = $entry;
                continue;
            }
            if (!$media->isExist($entry['path'])) {
                continue;
            }
            if ($this->isReferenced($entry['path'])) {
                continue; // deployed — forget it
            }
            try {
                $media->delete($entry['path']);
                $deleted++;
            } catch (\Throwable $e) {
                $this->logger->warning('MageDrop: could not delete staged media ' . $entry['path'] . ': ' . $e->getMessage());
                $keep[] = $entry;
            }
        }

        $this->log->replace($keep);

        if ($deleted) {
            $this->logger->info("MageDrop: removed {$deleted} never-deployed staged media file(s).");
        }
    }

    private function isReferenced(string $mediaPath): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $basename = basename($mediaPath);

        // Product gallery stores "/a/b/file.jpg"
        $gallery = $connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('catalog_product_entity_media_gallery'), [new \Zend_Db_Expr('COUNT(*)')])
                ->where('value LIKE ?', '%/' . $basename)
        );
        if ((int) $gallery > 0) {
            return true;
        }

        // Category image attributes store "/media/catalog/category/file.jpg" or a bare filename
        $category = $connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('catalog_category_entity_varchar'), [new \Zend_Db_Expr('COUNT(*)')])
                ->where('value LIKE ?', '%' . $basename)
        );

        return (int) $category > 0;
    }
}
