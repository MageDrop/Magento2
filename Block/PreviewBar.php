<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Block;

use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class PreviewBar extends Template
{
    public const CONTEXT_PREVIEW = 'magedrop_preview_release';

    public function __construct(
        Context $context,
        private HttpContext $httpContext,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isPreviewActive(): bool
    {
        return (bool) $this->getReleaseId();
    }

    public function getReleaseId(): ?int
    {
        $value = $this->httpContext->getValue(self::CONTEXT_PREVIEW);

        if (!$value) {
            return null;
        }

        // Value is "releaseId:changesHash" — extract the release ID
        $parts = explode(':', (string) $value, 2);

        return (int) $parts[0] ?: null;
    }

    public function getCacheLifetime(): ?int
    {
        return null;
    }
}
