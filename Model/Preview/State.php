<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Preview;

use MageDrop\Magento2\Block\PreviewBar;
use Magento\Framework\App\Http\Context as HttpContext;

/**
 * Read-only view of the current request's preview state.
 * Reads HttpContext (populated by PreviewContext plugin in beforeDispatch) —
 * safe on cacheable GETs because HttpContext survives FPC depersonalisation.
 */
class State
{
    public function __construct(
        private HttpContext $httpContext
    ) {
    }

    public function isActive(): bool
    {
        return $this->getReleaseId() !== null;
    }

    public function getReleaseId(): ?int
    {
        $value = $this->httpContext->getValue(PreviewBar::CONTEXT_PREVIEW);
        if (!$value) {
            return null;
        }

        $releaseId = (int) explode(':', (string) $value, 2)[0];

        return $releaseId ?: null;
    }
}
