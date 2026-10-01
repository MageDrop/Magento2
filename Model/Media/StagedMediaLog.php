<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Media;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * Records media MageDrop may leave unused, so Cron/CleanStagedMedia can sweep it
 * once it can no longer be needed:
 *  - kind "product"/"category": files staging moved into their final location
 *    before any DB row references them (unused if the release never deploys)
 *  - kind "unlinked_gallery": gallery images a deploy removed from a product; the
 *    row and file are kept so a rollback can re-link them
 * One JSON object per line in var/magedrop/staged-media.log.
 */
class StagedMediaLog
{
    private const FILE = 'magedrop/staged-media.log';

    public function __construct(
        private Filesystem $filesystem,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @param string $mediaPath path relative to pub/media, e.g. "catalog/product/a/b/x.jpg"
     */
    public function record(string $mediaPath, string $kind, array $extra = []): void
    {
        try {
            $dir = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
            $line = json_encode(['path' => ltrim($mediaPath, '/'), 'kind' => $kind, 'at' => time()] + $extra) . "\n";
            $dir->writeFile(self::FILE, $line, 'a');
        } catch (\Throwable $e) {
            $this->logger->warning('MageDrop: could not record staged media file: ' . $e->getMessage());
        }
    }

    /**
     * @return array<int, array{path: string, kind: string, at: int, value_id?: int}>
     */
    public function all(): array
    {
        try {
            $dir = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
            if (!$dir->isExist(self::FILE)) {
                return [];
            }
            $entries = [];
            foreach (explode("\n", $dir->readFile(self::FILE)) as $line) {
                $decoded = json_decode(trim($line), true);
                if (is_array($decoded) && !empty($decoded['path'])) {
                    $entries[] = ['path' => (string) $decoded['path'], 'kind' => (string) ($decoded['kind'] ?? ''), 'at' => (int) ($decoded['at'] ?? 0)]
                        + (isset($decoded['value_id']) ? ['value_id' => (int) $decoded['value_id']] : []);
                }
            }

            return $entries;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<int, array{path: string, kind: string, at: int}> $entries
     */
    public function replace(array $entries): void
    {
        try {
            $dir = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
            $content = '';
            foreach ($entries as $entry) {
                $content .= json_encode($entry) . "\n";
            }
            $dir->writeFile(self::FILE, $content);
        } catch (\Throwable $e) {
            $this->logger->warning('MageDrop: could not rewrite staged media log: ' . $e->getMessage());
        }
    }
}
