<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\App\Action;

use MageDrop\Magento2\Block\PreviewBar;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\RequestInterface;

class PreviewContext
{
    public function __construct(
        private CustomerSession $session,
        private HttpContext $httpContext
    ) {
    }

    public function beforeDispatch(
        Action $subject,
        RequestInterface $request
    ): void {
        
        $varyValue = $this->session->getData('magedrop_preview_vary');
        if ($varyValue) {
            $this->httpContext->setValue(
                PreviewBar::CONTEXT_PREVIEW,
                $varyValue,
                false
            );
        }
    }
}
