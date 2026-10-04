<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Block;

use MageDrop\Magento2\Model\Preview\BarInfo;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class PreviewBar extends Template
{
    public const CONTEXT_PREVIEW = 'magedrop_preview_release';

    public function __construct(
        Context $context,
        private HttpContext $httpContext,
        private BarInfo $barInfo,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Previewers only: add the bar's stylesheet to <head>. Runs while the layout is
     * generated, before the head renders; shoppers' pages get no MageDrop assets.
     */
    protected function _prepareLayout()
    {
        if ($this->isPreviewActive()) {
            $this->pageConfig->addPageAsset('MageDrop_Magento2::css/preview-bar.css');
        }

        return parent::_prepareLayout();
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

    /**
     * Release name, change count and dashboard link stored when the preview started.
     *
     * @return array{name: ?string, quick: bool, changes: ?int, dashboard_url: ?string}
     */
    public function getPreviewInfo(): array
    {
        $info = $this->barInfo->get();

        return [
            'name' => isset($info['name']) && $info['name'] !== '' ? (string) $info['name'] : null,
            'quick' => !empty($info['quick']),
            'changes' => isset($info['changes']) ? (int) $info['changes'] : null,
            'dashboard_url' => $info['dashboard_url'] ?? null,
        ];
    }

    public function getCacheLifetime(): ?int
    {
        return null;
    }
}
