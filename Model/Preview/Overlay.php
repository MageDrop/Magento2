<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Preview;

use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Framework\DataObject;
use Psr\Log\LoggerInterface;

/**
 * Applies a release's staged changes to a CMS entity in-memory.
 *
 * All of a release's staged changes are fetched in a single SaaS request on
 * first use and held for the rest of the request, so any number of CMS pages
 * and blocks rendered on a page costs at most one API call.
 */
class Overlay
{
    /** @var array<string, array<string, mixed>>|null map of "entityType:id" => changes */
    private ?array $changeMap = null;

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
        return $this->getChangeMap()[$entityType . ':' . $entityId] ?? [];
    }

    /**
     * Load (once per request) the full set of staged changes for the active
     * release, keyed by "entityType:id".
     *
     * @return array<string, array<string, mixed>>
     */
    private function getChangeMap(): array
    {
        if ($this->changeMap !== null) {
            return $this->changeMap;
        }

        try {
            $this->changeMap = $this->apiClient->getAllPreviewChanges(
                (int) $this->state->getReleaseId()
            );
        } catch (\Throwable $e) {
            $this->logger->error('MageDrop preview overlay error: ' . $e->getMessage());
            $this->changeMap = [];
        }

        return $this->changeMap;
    }
}
