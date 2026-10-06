<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Block\Adminhtml;

use MageDrop\Magento2\Model\Entity\AdapterPool;
use MageDrop\Magento2\Model\Service\ApiClient;
use MageDrop\Magento2\Plugin\Adminhtml\StageSavePlugin;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\Session as BackendSession;

/**
 * Post-redirect notices on entity edit forms:
 *  - ?magedrop_load=<release>  → banner "N field(s) loaded from release X"
 *    (with magedrop_preview: the Quick Preview edit put back, or "no longer available")
 *  - ?magedrop_preview=1       → Quick Preview result modal (URL held in the backend session)
 */
class LoadNotice extends Template
{
    public function __construct(
        Context $context,
        private ApiClient $apiClient,
        private AdapterPool $adapterPool,
        private BackendSession $backendSession,
        private string $entityType = '',
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getReleaseId(): int
    {
        return (int) $this->getRequest()->getParam('magedrop_load', 0);
    }

    protected function _toHtml(): string
    {
        if (!$this->apiClient->isEnabled()) {
            return '';
        }

        $html = '';

        if ($this->getReleaseId() > 0) {
            $html .= $this->renderLoadNotice();
        }

        if ($this->getRequest()->getParam('magedrop_preview')) {
            $html .= $this->renderPreviewResult();
        }

        return $html;
    }

    private function renderLoadNotice(): string
    {
        $releaseId = $this->getReleaseId();

        try {
            $adapter = $this->adapterPool->get($this->entityType);
        } catch (\Throwable) {
            return '';
        }

        $entityId = (string) ($adapter->resolveEntityId($this->getRequest()) ?? '');
        $storeId = $adapter->resolveStoreId($this->getRequest());

        $config = [
            'releaseId' => $releaseId,
            'changeCount' => 0,
            'releaseName' => '',
            'quick' => (bool) $this->getRequest()->getParam('magedrop_preview'),
        ];

        foreach ($config['quick'] ? [] : $this->apiClient->getReleases() as $release) {
            if ((int) ($release['id'] ?? 0) === $releaseId) {
                $config['releaseName'] = (string) ($release['name'] ?? '');
                break;
            }
        }

        if ($entityId !== '') {
            foreach ($this->apiClient->getPreviewChanges($releaseId, $this->entityType, $entityId) as $group) {
                $scope = (int) ($group['scope_store_id'] ?? 0);
                if ($scope === 0 || $scope === $storeId) {
                    $config['changeCount'] += count($group['changes']);
                }
            }
        }

        $config['dismissUrl'] = $this->getUrl($adapter->getEditRoute(), $adapter->getEditParams($entityId, $storeId));

        return $this->initScript(['MageDrop_Magento2/js/load-notice' => $config]);
    }

    private function renderPreviewResult(): string
    {
        $result = $this->backendSession->getData(StageSavePlugin::SESSION_PREVIEW_RESULT, true);
        if (!is_array($result) || empty($result['preview_url'])) {
            return '';
        }

        return $this->initScript([
            'MageDrop_Magento2/js/quick-preview-result' => [
                'previewUrl' => (string) $result['preview_url'],
                'changeCount' => (int) ($result['change_count'] ?? 0),
            ],
        ]);
    }

    private function initScript(array $components): string
    {
        return '<script type="text/x-magento-init">{"*": ' . json_encode($components) . '}</script>';
    }
}
