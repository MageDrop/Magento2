<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Preview;

/**
 * Preview bar details (release name, change count, dashboard link) for this request.
 *
 * Filled by the PreviewContext plugin at dispatch: on full-page-cacheable pages Magento's
 * DepersonalizePlugin clears the customer session while the layout is built, so the bar
 * block can't read them from the session itself.
 */
class BarInfo
{
    private array $info = [];

    public function set(array $info): void
    {
        $this->info = $info;
    }

    public function get(): array
    {
        return $this->info;
    }
}
