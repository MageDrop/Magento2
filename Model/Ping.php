<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model;

use MageDrop\Magento2\Api\PingInterface;
use MageDrop\Magento2\Model\Service\ApiClient;

class Ping implements PingInterface
{
    public function __construct(
        private ApiClient $apiClient
    ) {
    }

    public function ping(): string
    {
        if (!$this->apiClient->isEnabled()) {
            return 'Module is disabled';
        }

        $result = $this->apiClient->handshake();

        if ($result && isset($result['store_id'])) {
            return 'ok';
        }

        return 'Failed to connect to MageDrop API';
    }
}
