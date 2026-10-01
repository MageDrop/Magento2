<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Service;

use MageDrop\Magento2\Model\Version;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * HTTP client for the MageDrop SaaS module API. Every request carries the
 * module version so the SaaS can shape responses for this protocol level.
 */
class ApiClient
{
    private const API_BASE_URL = 'https://www.magedrop.com/api';
    public const VERSION_HEADER = 'X-MageDrop-Module-Version';
    private const XML_PATH_API_URL = 'magedrop/general/api_url';

    public function __construct(
        private ScopeConfigInterface $scopeConfig,
        private EncryptorInterface $encryptor,
        private Curl $curl,
        private Json $json,
        private LoggerInterface $logger
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue('magedrop/general/enabled');
    }

    /**
     * Verify the connection and publish this module's capabilities.
     *
     * @param array $capabilities {module_version, entity_types, store_views, features}
     */
    public function handshake(array $capabilities = []): ?array
    {
        if ($capabilities) {
            return $this->request('POST', 'handshake', $capabilities);
        }

        return $this->request('GET', 'handshake');
    }

    public function getReleases(): array
    {
        $response = $this->request('GET', 'releases');

        return $response ?? [];
    }

    /**
     * Stage a pre-computed delta to a release.
     *
     * @param array $payload {entity_type, entity_id, entity_title, scope_store_id, changes[]}
     */
    public function stageDelta(int $releaseId, array $payload): array
    {
        return $this->request('POST', 'stage', ['release_id' => $releaseId] + $payload) ?? [];
    }

    /**
     * Create a temporary preview release from a pre-computed delta.
     */
    public function quickPreviewDelta(array $payload): array
    {
        return $this->request('POST', 'quick-preview', $payload) ?? [];
    }

    /**
     * Staged change groups for one entity.
     *
     * @return array<int, array{scope_store_id: int|null, changes: array<string, array>}>
     */
    public function getPreviewChanges(int $releaseId, string $entityType, string $entityId): array
    {
        $response = $this->request('POST', 'preview', [
            'release_id' => $releaseId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);

        return $this->normaliseGroups($response['groups'] ?? []);
    }

    /**
     * Every staged change for a release in one request, grouped by entity and scope.
     *
     * @return array<string, array<int, array{scope_store_id: int|null, changes: array<string, array>}>>
     *         map of "entityType:entityId" => groups
     */
    public function getAllPreviewChanges(int $releaseId): array
    {
        $response = $this->request('POST', 'preview/all', [
            'release_id' => $releaseId,
        ]);

        $map = [];
        foreach ($response['entities'] ?? [] as $entity) {
            if (!isset($entity['entity_type'], $entity['entity_id'])) {
                continue;
            }
            $key = $entity['entity_type'] . ':' . $entity['entity_id'];
            $map[$key][] = [
                'scope_store_id' => isset($entity['scope_store_id']) ? (int) $entity['scope_store_id'] : null,
                'changes' => is_array($entity['changes'] ?? null) ? $entity['changes'] : [],
            ];
        }

        return $map;
    }

    public function validatePreviewToken(int $releaseId, string $previewToken): bool
    {
        $result = $this->validatePreviewTokenFull($releaseId, $previewToken);

        return $result['valid'] ?? false;
    }

    public function validatePreviewTokenFull(int $releaseId, string $previewToken): ?array
    {
        $url = $this->baseUrl() . '/preview/validate';

        return $this->requestRaw('POST', $url, [
            'release_id' => $releaseId,
            'preview_token' => $previewToken,
        ]);
    }

    /**
     * @param array<string, array> $values field => Value envelope
     */
    public function saveRevision(
        string $entityType,
        string $entityId,
        array $values,
        ?string $adminUser = null,
        ?int $scopeStoreId = null,
        ?string $title = null,
        ?string $source = null
    ): array {
        return $this->request('POST', 'revision', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'scope_store_id' => $scopeStoreId,
            'title' => $title,
            'values' => $values,
            'admin_user' => $adminUser,
            'source' => $source,
        ]) ?? [];
    }

    /**
     * @return array<int, array{scope_store_id: int|null, changes: array<string, array>}>
     */
    private function normaliseGroups(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $out[] = [
                'scope_store_id' => isset($group['scope_store_id']) ? (int) $group['scope_store_id'] : null,
                'changes' => is_array($group['changes'] ?? null) ? $group['changes'] : [],
            ];
        }

        return $out;
    }

    /**
     * Make a request to a public endpoint (no auth token).
     */
    private function requestRaw(string $method, string $url, array $data = []): ?array
    {
        $this->prepareHeaders(null);

        try {
            $this->curl->post($url, $this->json->serialize($data));

            $status = $this->curl->getStatus();
            $body = $this->curl->getBody();

            if ($status >= 200 && $status < 300) {
                return $this->json->unserialize($body);
            }

            $this->logger->warning('MageDrop public API error', [
                'status' => $status,
                'url' => $url,
                'body' => substr((string) $body, 0, 500),
            ]);

            return null;
        } catch (\Exception $e) {
            $this->logger->error('MageDrop API exception: ' . $e->getMessage());
            return null;
        }
    }

    private function request(string $method, string $endpoint, array $data = []): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $token = $this->encryptor->decrypt(
            $this->scopeConfig->getValue('magedrop/general/api_token') ?? ''
        );

        if (!$token) {
            $this->logger->warning('MageDrop: Module token not configured');
            return null;
        }

        $url = $this->baseUrl() . '/module/' . ltrim($endpoint, '/');

        $this->prepareHeaders($token);

        try {
            if ($method === 'GET') {
                $this->curl->get($url);
            } else {
                $this->curl->post($url, $this->json->serialize($data));
            }

            $status = $this->curl->getStatus();
            $body = $this->curl->getBody();

            if ($status >= 200 && $status < 300) {
                $decoded = $this->json->unserialize($body);

                return is_array($decoded) ? $decoded : [];
            }

            $this->logger->error('MageDrop API error', [
                'status' => $status,
                'url' => $url,
                'body' => substr((string) $body, 0, 1000),
            ]);

            $decoded = null;
            try {
                $decoded = $this->json->unserialize($body);
            } catch (\Throwable) {
                // non-JSON error body
            }
            if (is_array($decoded) && (isset($decoded['error']) || isset($decoded['message']))) {
                return ['error' => (string) ($decoded['error'] ?? $decoded['message'])];
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error('MageDrop API exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Production SaaS URL unless an override is configured (staging / local dev).
     */
    private function baseUrl(): string
    {
        $override = trim((string) $this->scopeConfig->getValue(self::XML_PATH_API_URL));

        return $override !== '' ? rtrim($override, '/') : self::API_BASE_URL;
    }

    private function prepareHeaders(?string $token): void
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            self::VERSION_HEADER => Version::VERSION,
        ];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $this->curl->setHeaders($headers);
        $this->curl->setTimeout(15);
    }
}
