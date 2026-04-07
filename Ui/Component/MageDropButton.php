<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Ui\Component;

use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Backend\Block\Widget\Context;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class MageDropButton implements ButtonProviderInterface
{
    public function __construct(
        private ApiClient $apiClient,
        private Context $context,
        private string $entityType = '',
        private string $entityIdParam = '',
        private string $formName = ''
    ) {
    }

    public function getButtonData(): array
    {
        if (!$this->apiClient->isEnabled()) {
            return [];
        }

        $entityId = (string) ($this->context->getRequest()->getParam($this->entityIdParam) ?? '');

        $options = [];

        $options[] = [
            'label' => __('Quick Preview'),
            'id_hard' => 'magedrop-quick-preview',
            'data_attribute' => [
                'mage-init' => [
                    'MageDrop_Magento2/js/quick-preview' => [
                        'previewUrl' => $this->context->getUrlBuilder()->getUrl('magedrop/stage/quickpreview'),
                        'formName' => $this->formName,
                        'entityType' => $this->entityType,
                        'entityId' => $entityId,
                        'entityIdKey' => $this->entityIdParam,
                    ],
                ],
            ],
        ];

        $options[] = [
            'label' => __('Load from Release'),
            'id_hard' => 'magedrop-load-changes',
            'data_attribute' => [
                'mage-init' => [
                    'MageDrop_Magento2/js/load-changes' => [
                        'releasesUrl' => $this->context->getUrlBuilder()->getUrl('magedrop/release/releases'),
                        'checkUrl' => $this->context->getUrlBuilder()->getUrl('magedrop/stage/loadchanges'),
                        'formName' => $this->formName,
                        'entityType' => $this->entityType,
                        'entityId' => $entityId,
                        'entityIdKey' => $this->entityIdParam,
                    ],
                ],
            ],
        ];

        $options[] = [
            'label' => __('Save & Stage'),
            'id_hard' => 'magedrop-save-stage',
            'data_attribute' => [
                'mage-init' => [
                    'MageDrop_Magento2/js/save-stage' => [
                        'releasesUrl' => $this->context->getUrlBuilder()->getUrl('magedrop/release/releases'),
                        'stageUrl' => $this->context->getUrlBuilder()->getUrl('magedrop/stage/savestage'),
                        'formName' => $this->formName,
                        'entityType' => $this->entityType,
                        'entityId' => $entityId,
                        'entityIdKey' => $this->entityIdParam,
                    ],
                ],
            ],
        ];

        return [
            'label' => __('MageDrop'),
            'class' => 'magedrop-button',
            'class_name' => \Magento\Backend\Block\Widget\Button\SplitButton::class,
            'options' => $options,
            'sort_order' => 100,
        ];
    }
}
