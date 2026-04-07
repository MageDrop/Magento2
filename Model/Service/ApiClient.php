<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

class ApiClient
{
    private const API_BASE_URL = 'https://www.magedrop.com/api';

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

    public function handshake(): ?array
    {
        return $this->request('GET', 'handshake');
    }

    public function getReleases(): array
    {
        $response = $this->request('GET', 'releases');

        return $response ?? [];
    }

    public function stageEntity(int $releaseId, string $entityType, string $entityId, array $formData): array
    {
        return $this->request('POST', 'stage-entity', [
            'release_id' => $releaseId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'form_data' => $formData,
        ]) ?? [];
    }

    public function getPreviewChanges(int $releaseId, string $entityType, string $entityId): array
    {
        $response = $this->request('POST', 'preview', [
            'release_id' => $releaseId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);

        return $response['changes'] ?? [];
    }

    public function validatePreviewToken(int $releaseId, string $previewToken): bool
    {
        $result = $this->validatePreviewTokenFull($releaseId, $previewToken);

        return $result['valid'] ?? false;
    }

    public function validatePreviewTokenFull(int $releaseId, string $previewToken): ?array
    {
        $url = self::API_BASE_URL . '/preview/validate';

        $this->logger->info('MageDrop validatePreviewToken: calling', [
            'url' => $url,
            'release_id' => $releaseId,
            'token_length' => strlen($previewToken),
        ]);

        $response = $this->requestRaw('POST', $url, [
            'release_id' => $releaseId,
            'preview_token' => $previewToken,
        ]);

        $this->logger->info('MageDrop validatePreviewToken: response', [
            'response' => $response,
        ]);

        return $response;
    }

    public function saveRevision(string $entityType, string $entityId, array $data, ?string $adminUser = null): array
    {
        return $this->request('POST', 'revision', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'data' => $data,
            'admin_user' => $adminUser,
        ]) ?? [];
    }

    public function quickPreview(string $entityType, string $entityId, array $formData): array
    {
        return $this->request('POST', 'quick-preview', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'form_data' => $formData,
        ]) ?? [];
    }

    /**
     * Make a request to a public endpoint (no auth token).
     */
    private function requestRaw(string $method, string $url, array $data = []): ?array
    {
        $this->curl->addHeader('Accept', 'application/json');
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->setTimeout(10);

        try {
            $this->curl->post($url, $this->json->serialize($data));

            $status = $this->curl->getStatus();
            $body = $this->curl->getBody();

            $this->logger->info('MageDrop requestRaw: response', [
                'status' => $status,
                'body' => substr($body, 0, 500),
                'sent_data' => $this->json->serialize($data),
            ]);

            if ($status >= 200 && $status < 300) {
                return $this->json->unserialize($body);
            }

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

        $url = self::API_BASE_URL . '/module/' . ltrim($endpoint, '/');

        $this->curl->addHeader('Authorization', 'Bearer ' . $token);
        $this->curl->addHeader('Accept', 'application/json');
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->setTimeout(10);

        try {
            if ($method === 'GET') {
                $this->curl->get($url);
            } else {
                $this->curl->post($url, $this->json->serialize($data));
            }

            $status = $this->curl->getStatus();
            $body = $this->curl->getBody();

            if ($status >= 200 && $status < 300) {
                return $this->json->unserialize($body);
            }

            $this->logger->error('MageDrop API error', [
                'status' => $status,
                'url' => $url,
                'body' => $body,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->logger->error('MageDrop API exception: ' . $e->getMessage());
            return null;
        }
    }
}
