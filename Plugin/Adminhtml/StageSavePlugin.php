<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Adminhtml;

use MageDrop\Magento2\Model\Entity\AdapterPool;
use MageDrop\Magento2\Model\Service\ApiClient;
use MageDrop\Magento2\Model\Staging\Stager;
use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Intercepts the real admin Save controller. When the form was submitted with
 * a MageDrop flag (set by save-stage.js / quick-preview.js through
 * form.save(redirect, data)), the exact POST Magento would have persisted is
 * diffed against the live entity and shipped to the SaaS instead of saved.
 *
 * Subclasses only bind the entity type code (concrete classes are required
 * because di:compile cannot build interceptors for virtual types).
 */
class StageSavePlugin
{
    public const PARAM_STAGE = 'magedrop_stage';
    public const PARAM_RELEASE = 'magedrop_release_id';
    public const PARAM_QUICK_PREVIEW = 'magedrop_quick_preview';
    public const SESSION_PREVIEW_RESULT = 'magedrop_quick_preview_result';

    public function __construct(
        private Stager $stager,
        private AdapterPool $adapterPool,
        private ApiClient $apiClient,
        private RedirectFactory $redirectFactory,
        private ManagerInterface $messageManager,
        private BackendSession $backendSession,
        private LoggerInterface $logger,
        private string $entityType = ''
    ) {
    }

    public function aroundExecute($subject, callable $proceed)
    {
        $request = $subject->getRequest();

        $wantsStage = (bool) $request->getParam(self::PARAM_STAGE);
        $wantsPreview = (bool) $request->getParam(self::PARAM_QUICK_PREVIEW);

        if (!$wantsStage && !$wantsPreview) {
            return $proceed();
        }

        if (!$this->apiClient->isEnabled()) {
            $this->messageManager->addErrorMessage(__('MageDrop is disabled. Enable it under Stores > Configuration > MageDrop.'));

            return $this->redirectBack($request);
        }

        try {
            return $wantsStage
                ? $this->handleStage($request)
                : $this->handleQuickPreview($request);
        } catch (\Throwable $e) {
            $this->logger->error('MageDrop staging error: ' . $e->getMessage(), ['exception' => $e]);
            $this->messageManager->addErrorMessage(__('MageDrop error: %1', $e->getMessage()));
        }

        return $this->redirectBack($request);
    }

    private function handleStage(RequestInterface $request)
    {
        $releaseId = (int) $request->getParam(self::PARAM_RELEASE);

        if (!$releaseId) {
            $this->messageManager->addErrorMessage(__('No release selected.'));

            return $this->redirectBack($request);
        }

        $result = $this->stager->stage($this->entityType, $request, $releaseId);

        if ($result['change_count'] === 0) {
            $this->messageManager->addNoticeMessage(
                __('No changes detected — the form matches what is already live. Nothing was staged.')
            );

            return $this->redirectBack($request);
        }

        $response = $result['response'];

        if (empty($response)) {
            $this->messageManager->addErrorMessage(__('Failed to communicate with the MageDrop API.'));

            return $this->redirectBack($request);
        }

        if (!empty($response['error'])) {
            $this->messageManager->addErrorMessage(__($response['error']));

            return $this->redirectBack($request);
        }

        $this->messageManager->addSuccessMessage(
            __(
                'Staged %1 change(s) to release "%2". The form now shows the staged values; nothing has been saved to the live store.',
                $response['change_count'] ?? $result['change_count'],
                $response['release'] ?? ''
            )
        );

        return $this->redirectBack($request, ['magedrop_load' => $releaseId]);
    }

    private function handleQuickPreview(RequestInterface $request)
    {
        $result = $this->stager->quickPreview($this->entityType, $request);

        if ($result['change_count'] === 0) {
            $this->messageManager->addNoticeMessage(
                __('No changes detected — the form matches what is already live. Nothing to preview.')
            );

            return $this->redirectBack($request);
        }

        $response = $result['response'];

        if (empty($response) || !empty($response['error']) || empty($response['preview_url'])) {
            $this->messageManager->addErrorMessage(
                __('Failed to create preview: %1', $response['error'] ?? 'no response from MageDrop')
            );

            return $this->redirectBack($request);
        }

        $this->backendSession->setData(self::SESSION_PREVIEW_RESULT, [
            'preview_url' => (string) $response['preview_url'],
            'change_count' => (int) ($response['change_count'] ?? $result['change_count']),
        ]);

        return $this->redirectBack($request, ['magedrop_preview' => 1]);
    }

    private function redirectBack(RequestInterface $request, array $extraParams = [])
    {
        $adapter = $this->adapterPool->get($this->entityType);
        $entityId = (string) ($adapter->resolveEntityId($request) ?? '');
        $storeId = $adapter->resolveStoreId($request);

        $params = $adapter->getEditParams($entityId, $storeId) + $extraParams;

        return $this->redirectFactory->create()->setPath($adapter->getEditRoute(), $params);
    }
}
