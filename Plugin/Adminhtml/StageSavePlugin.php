<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Adminhtml;

use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;

class StageSavePlugin
{
    public function __construct(
        private ApiClient $apiClient,
        private RedirectFactory $redirectFactory,
        private ManagerInterface $messageManager,
        private LoggerInterface $logger,
        private string $entityType = '',
        private string $entityIdParam = '',
        private string $editRoute = ''
    ) {
    }

    public function aroundExecute($subject, callable $proceed)
    {
        $request = $subject->getRequest();

        if (!$request->getParam('magedrop_stage')) {
            return $proceed();
        }

        try {
            $entityId = (string) ($request->getParam($this->entityIdParam) ?? '');
            $formData = $this->extractFormData($request);

            return $this->handleStage($request, $entityId, $formData);
        } catch (\Exception $e) {
            $this->logger->error('MageDrop error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('MageDrop error: %1', $e->getMessage()));
        }

        return $this->redirectBack($request);
    }

    private function handleStage(RequestInterface $request, string $entityId, array $formData)
    {
        $releaseId = (int) $request->getParam('magedrop_release_id');

        if (!$releaseId) {
            $this->messageManager->addErrorMessage(__('No release selected.'));
            return $this->redirectBack($request);
        }

        $response = $this->apiClient->stageEntity($releaseId, $this->entityType, $entityId, $formData);

        if (empty($response)) {
            $this->messageManager->addErrorMessage(__('Failed to communicate with MageDrop API.'));
            return $this->redirectBack($request);
        }

        if (!empty($response['error'])) {
            $this->messageManager->addNoticeMessage(__($response['error']));
            return $this->redirectBack($request);
        }

        $changeCount = $response['change_count'] ?? 0;
        $releaseName = $response['release'] ?? '';

        $this->messageManager->addSuccessMessage(
            __('Staged %1 change(s) to release "%2".', $changeCount, $releaseName)
        );

        return $this->redirectBack($request, $releaseId);
    }

    private function extractFormData(RequestInterface $request): array
    {
        $raw = $request->getParams();

        $ignored = [
            'form_key', 'key', 'isAjax', 'is_active_filter',
            'back', 'redirect_to_store', 'reset',
            'entity_id', 'row_id', 'page_id', 'block_id',
            'store_id', 'identifier',
            'created_at', 'updated_at', 'created_in', 'updated_in',
            'layout_update_selected', 'layout_update_xml', 'custom_layout_update_xml',
            'custom_design', 'custom_design_from', 'custom_design_to',
            'custom_theme', 'custom_root_template', 'page_layout',
            'magedrop_stage', 'magedrop_release_id',
            'use_default', 'use_config',
        ];

        $filtered = [];
        foreach ($raw as $key => $value) {
            if (in_array($key, $ignored, true)) {
                continue;
            }
            if (str_starts_with($key, 'use_config_') || str_starts_with($key, 'use_default_')) {
                continue;
            }
            if (!is_scalar($value) && $value !== null) {
                continue;
            }
            $filtered[$key] = $value;
        }

        return $filtered;
    }

    private function redirectBack(RequestInterface $request, ?int $stagedReleaseId = null)
    {
        $redirect = $this->redirectFactory->create();
        $entityId = (string) ($request->getParam($this->entityIdParam) ?? '');

        $params = [$this->entityIdParam => $entityId];

        if ($stagedReleaseId) {
            $params['magedrop_load'] = $stagedReleaseId;
        }

        return $redirect->setPath($this->editRoute, $params);
    }
}
