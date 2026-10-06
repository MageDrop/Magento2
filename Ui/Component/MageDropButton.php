<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Ui\Component;

use MageDrop\Magento2\Model\Entity\AdapterPool;
use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Backend\Block\Widget\Context;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * The MageDrop split button on entity edit forms. Configured per form through
 * di.xml virtual types (entityType + formName).
 */
class MageDropButton implements ButtonProviderInterface
{
    public function __construct(
        private ApiClient $apiClient,
        private AdapterPool $adapterPool,
        private Context $context,
        private string $entityType = '',
        private string $formName = ''
    ) {
    }

    public function getButtonData(): array
    {
        if (!$this->apiClient->isEnabled() || !$this->adapterPool->has($this->entityType)) {
            return [];
        }

        $adapter = $this->adapterPool->get($this->entityType);
        $request = $this->context->getRequest();

        $entityId = (string) ($adapter->resolveEntityId($request) ?? '');
        if ($entityId === '') {
            // Nothing to stage against until the entity exists
            return [];
        }

        $storeId = $adapter->resolveStoreId($request);

        $common = [
            'formName' => $this->formName,
            'entityType' => $this->entityType,
            'entityId' => $entityId,
            'entityIdKey' => $adapter->getFormIdKey(),
            'storeId' => $storeId,
        ];

        $options = [];

        $options[] = [
            'label' => __('Quick Preview'),
            'id_hard' => 'magedrop-quick-preview',
            'data_attribute' => [
                'mage-init' => [
                    'MageDrop_Magento2/js/quick-preview' => $common,
                ],
            ],
        ];

        $options[] = [
            'label' => __('Load from Release'),
            'id_hard' => 'magedrop-load-changes',
            'data_attribute' => [
                'mage-init' => [
                    'MageDrop_Magento2/js/load-changes' => $common + [
                        'releasesUrl' => $this->context->getUrlBuilder()->getUrl('magedrop/release/releases'),
                        'checkUrl' => $this->context->getUrlBuilder()->getUrl('magedrop/stage/loadchanges'),
                    ],
                ],
            ],
        ];

        $options[] = [
            'label' => __('Save & Stage'),
            'id_hard' => 'magedrop-save-stage',
            'data_attribute' => [
                'mage-init' => [
                    'MageDrop_Magento2/js/save-stage' => $common + [
                        'releasesUrl' => $this->context->getUrlBuilder()->getUrl('magedrop/release/releases'),
                    ],
                ],
            ],
        ];

        return [
            'label' => __('MageDrop'),
            'class' => 'magedrop-button',
            'data_attribute' => [
                'mage-init' => ['MageDrop_Magento2/js/open-menu' => []],
            ],
            'class_name' => \Magento\Backend\Block\Widget\Button\SplitButton::class,
            'options' => $options,
            'sort_order' => 100,
        ];
    }
}
