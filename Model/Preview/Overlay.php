<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Preview;

use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Framework\DataObject;
use Psr\Log\LoggerInterface;

/**
 * Applies a release's staged changes to a CMS entity in-memory.
 * Caches per-request — each (entity_type, id) hits the SaaS at most once per render.
 */
class Overlay
{
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct(
        private State $state,
        private ApiClient $apiClient,
        private LoggerInterface $logger
    ) {
    }

    public function applyTo(DataObject $entity, string $entityType): bool
    {
        if (!$this->state->isActive()) {
            return false;
        }

        $entityId = (int) $entity->getId();
        if (!$entityId) {
            return false;
        }

        $changes = $this->fetchChanges($entityType, $entityId);
        if (!$changes) {
            return false;
        }

        foreach ($changes as $field => $value) {
            $entity->setData($field, $value);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchChanges(string $entityType, int $entityId): array
    {
        $cacheKey = $entityType . ':' . $entityId;
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        try {
            $changes = $this->apiClient->getPreviewChanges(
                (int) $this->state->getReleaseId(),
                $entityType,
                (string) $entityId
            );
        } catch (\Throwable $e) {
            $this->logger->error('MageDrop preview overlay error (' . $entityType . ' ' . $entityId . '): ' . $e->getMessage());
            $changes = [];
        }

        return $this->cache[$cacheKey] = $changes;
    }
}
