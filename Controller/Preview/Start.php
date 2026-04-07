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
                $this->httpContext->setValue(
                    PreviewBar::CONTEXT_PREVIEW,
                    $varyValue,
                    false
                );
            }
        }

        return $this->resultRedirectFactory->create()
            ->setUrl($this->storeManager->getStore()->getBaseUrl());
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
