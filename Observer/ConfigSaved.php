<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Observer;

use MageDrop\Magento2\Model\Ping;
use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * After the MageDrop config section is saved, hand the SaaS this module's
 * version, entity types and store views straight away instead of waiting for
 * the next connection check.
 */
class ConfigSaved implements ObserverInterface
{
    public function __construct(
        private ApiClient $apiClient,
        private Ping $ping,
        private ManagerInterface $messageManager,
        private LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->apiClient->isEnabled()) {
            return;
        }

        try {
            $result = $this->apiClient->handshake($this->ping->capabilities());
            if ($result && isset($result['store_id'])) {
                $this->messageManager->addSuccessMessage(
                    __('MageDrop: connected to "%1".', $result['store_name'] ?? $result['store_id'])
                );
            } else {
                $this->messageManager->addWarningMessage(
                    __('MageDrop: could not reach the MageDrop API with this token. Check the token and API URL.')
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('MageDrop handshake after config save failed: ' . $e->getMessage());
        }
    }
}
