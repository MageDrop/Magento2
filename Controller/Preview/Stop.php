<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Controller\Preview;

use MageDrop\Magento2\Block\PreviewBar;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;

class Stop extends Action implements CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        private CustomerSession $session,
        private HttpContext $httpContext,
        private StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $this->session->unsetData('magedrop_preview_release_id');
        $this->session->unsetData('magedrop_preview_vary');
        $this->session->unsetData('magedrop_preview_info');
        $this->httpContext->setValue(PreviewBar::CONTEXT_PREVIEW, false, false);

        // Stay on the page being previewed (now showing the live content). Magento's
        // redirect helper only returns the referer when it is one of this store's URLs.
        $back = (string) $this->_redirect->getRefererUrl();

        return $this->resultRedirectFactory->create()
            ->setUrl($back !== '' ? $back : $this->storeManager->getStore()->getBaseUrl());
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
