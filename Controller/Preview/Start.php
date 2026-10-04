<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Controller\Preview;

use MageDrop\Magento2\Block\PreviewBar;
use MageDrop\Magento2\Model\Service\ApiClient;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;

class Start extends Action implements CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private CustomerSession $session,
        private ApiClient $apiClient,
        private HttpContext $httpContext,
        private StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $releaseId = (int) $this->getRequest()->getParam('release_id');
        $previewToken = $this->getRequest()->getParam('preview_token');

        if ($releaseId && $previewToken) {
            $result = $this->apiClient->validatePreviewTokenFull($releaseId, $previewToken);

            if ($result && !empty($result['valid'])) {
                $changesHash = $result['changes_hash'] ?? '';
                $varyValue = $releaseId . ':' . $changesHash;

                $this->session->setData('magedrop_preview_release_id', $releaseId);
                $this->session->setData('magedrop_preview_vary', $varyValue);
                // Shown on the preview bar (SaaS sends these from 2.0.2)
                $this->session->setData('magedrop_preview_info', [
                    'name' => isset($result['release_name']) ? (string) $result['release_name'] : null,
                    'quick' => !empty($result['is_quick_preview']),
                    'changes' => isset($result['change_count']) ? (int) $result['change_count'] : null,
                    'dashboard_url' => isset($result['dashboard_url']) && filter_var($result['dashboard_url'], FILTER_VALIDATE_URL)
                        ? (string) $result['dashboard_url'] : null,
                ]);
                $this->httpContext->setValue(
                    PreviewBar::CONTEXT_PREVIEW,
                    $varyValue,
                    false
                );
            }
        }

        return $this->resultRedirectFactory->create()->setUrl($this->landingUrl());
    }

    /**
     * Land on the staged entity's page when the SaaS supplied one and it lives on
     * one of this installation's store hosts; otherwise the store home page.
     */
    private function landingUrl(): string
    {
        $base = (string) $this->storeManager->getStore()->getBaseUrl();
        $redirect = trim((string) $this->getRequest()->getParam('redirect', ''));
        if ($redirect === '' || !filter_var($redirect, FILTER_VALIDATE_URL)) {
            return $base;
        }
        $host = strtolower((string) parse_url($redirect, PHP_URL_HOST));
        foreach ($this->storeManager->getStores(true) as $store) {
            foreach ([\Magento\Framework\UrlInterface::URL_TYPE_WEB] as $type) {
                $storeHost = strtolower((string) parse_url((string) $store->getBaseUrl($type), PHP_URL_HOST));
                if ($storeHost !== '' && $storeHost === $host) {
                    return $redirect;
                }
            }
        }

        return $base;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
