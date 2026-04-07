<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Observer;

use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class CmsSaveRevision implements ObserverInterface
{
    public function __construct(
        private ApiClient $apiClient,
        private AuthSession $authSession,
        private LoggerInterface $logger,
        private string $entityType = '',
        private string $entityIdField = 'id'
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->apiClient->isEnabled()) {
            return;
        }

        try {
            $object = $observer->getEvent()->getObject();

            $entityId = (string) $object->getData($this->entityIdField);
            if (!$entityId) {
                return;
            }

            $data = $this->extractScalarData($object->getData());
            $adminUser = $this->getAdminUsername();

            $this->apiClient->saveRevision($this->entityType, $entityId, $data, $adminUser);
        } catch (\Exception $e) {
            // Never block saves — log and continue
            $this->logger->error('MageDrop revision error: ' . $e->getMessage());
        }
    }

    private function extractScalarData(array $data): array
    {
        $filtered = [];
        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_')) {
                continue;
            }
            if (!is_scalar($value) && $value !== null) {
                continue;
            }
            $filtered[$key] = $value;
        }

        return $filtered;
    }

    private function getAdminUsername(): ?string
    {
        $user = $this->authSession->getUser();

        return $user ? $user->getUserName() : null;
    }
}
