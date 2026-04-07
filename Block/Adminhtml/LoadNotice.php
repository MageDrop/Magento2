<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Block\Adminhtml;

use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class LoadNotice extends Template
{
    public function __construct(
        Context $context,
        private ApiClient $apiClient,
        private string $entityType = '',
        private string $entityIdParam = '',
        private string $editRoute = '',
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getReleaseId(): int
    {
        return (int) $this->getRequest()->getParam('magedrop_load', 0);
    }

    public function isActive(): bool
    {
        return $this->getReleaseId() > 0 && $this->apiClient->isEnabled();
    }

    protected function _toHtml(): string
    {
        if (!$this->isActive()) {
            return '';
        }

        $releaseId = $this->getReleaseId();
        $entityId = (string) ($this->getRequest()->getParam($this->entityIdParam) ?? '');

        $config = [
            'releaseId' => $releaseId,
            'changeCount' => 0,
            'releaseName' => '',
        ];

        $releases = $this->apiClient->getReleases();
        foreach ($releases as $release) {
            if ((int) $release['id'] === $releaseId) {
                $config['releaseName'] = $release['name'];
                break;
            }
        }

        if ($entityId) {
            $changes = $this->apiClient->getPreviewChanges($releaseId, $this->entityType, $entityId);
            $config['changeCount'] = count($changes);
        }

        $config['dismissUrl'] = $this->getUrl($this->editRoute, [$this->entityIdParam => $entityId]);

        $json = json_encode(['MageDrop_Magento2/js/load-notice' => $config]);

        return '<script type="text/x-magento-init">{"*": ' . $json . '}</script>';
    }
}
