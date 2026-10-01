<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Observer;

use MageDrop\Magento2\Model\ApplyContext;
use MageDrop\Magento2\Model\Entity\AdapterPool;
use MageDrop\Magento2\Model\Service\ApiClient;
use MageDrop\Magento2\Model\Staging\Stager;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\Store;
use Psr\Log\LoggerInterface;

/**
 * Captures a full normalised snapshot of an entity after every save and sends
 * it to the SaaS as a revision. Adapter-driven, so the snapshot uses the same
 * section handlers (and Value envelopes) as staging and apply.
 *
 * Never blocks a save — every failure is logged and swallowed.
 */
class SaveRevision implements ObserverInterface
{
    public function __construct(
        private ApiClient $apiClient,
        private AdapterPool $adapterPool,
        private AuthSession $authSession,
        private ApplyContext $applyContext,
        private LoggerInterface $logger,
        private string $entityType = '',
        private bool $adminOnly = false
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->apiClient->isEnabled()) {
            return;
        }
        // Catalog observers also run in the REST area; there, only MageDrop's own
        // deploys/rollbacks count (imports and integrations would flood the history).
        if ($this->adminOnly && !$this->applyContext->isApplying() && !$this->isAdminArea()) {
            return;
        }

        try {
            $event = $observer->getEvent();
            $entity = $event->getData('data_object') ?? $event->getData('object');
            if (!$entity instanceof \Magento\Framework\DataObject || !$entity->getId()) {
                return;
            }

            $adapter = $this->adapterPool->get($this->entityType);

            $storeId = Store::DEFAULT_STORE_ID;
            if ($adapter->supportsStoreScope() && method_exists($entity, 'getStoreId')) {
                $storeId = (int) $entity->getStoreId();
            }

            $values = $adapter->current($entity, $storeId);
            if (!$values) {
                return;
            }

            $this->apiClient->saveRevision(
                $this->entityType,
                (string) $entity->getId(),
                Stager::encodeValues($values),
                $this->getAdminUsername(),
                $storeId === Store::DEFAULT_STORE_ID ? null : $storeId,
                $adapter->getTitle($entity),
                $this->applyContext->getSource()
            );
        } catch (\Throwable $e) {
            $this->logger->error('MageDrop revision error: ' . $e->getMessage());
        }
    }

    private function isAdminArea(): bool
    {
        try {
            return $this->authSession->isLoggedIn();
        } catch (\Throwable) {
            return false;
        }
    }

    private function getAdminUsername(): ?string
    {
        try {
            $user = $this->authSession->getUser();
        } catch (\Throwable) {
            return null;
        }

        return $user ? $user->getUserName() : null;
    }
}
