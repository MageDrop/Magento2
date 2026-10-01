<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Media;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * Records media files that staging moved into their final catalog location
 * before any DB row references them, so a cron can sweep the ones that were
 * never deployed. One JSON object per line in var/magedrop/staged-media.log.
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
    public function record(string $mediaPath, string $kind): void
    {
        try {
            $dir = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
            $line = json_encode(['path' => ltrim($mediaPath, '/'), 'kind' => $kind, 'at' => time()]) . "\n";
            $dir->writeFile(self::FILE, $line, 'a');
        } catch (\Throwable $e) {
            $this->logger->warning('MageDrop: could not record staged media file: ' . $e->getMessage());
        }
    }

    /**
     * @return array<int, array{path: string, kind: string, at: int}>
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
                    $entries[] = ['path' => (string) $decoded['path'], 'kind' => (string) ($decoded['kind'] ?? ''), 'at' => (int) ($decoded['at'] ?? 0)];
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
