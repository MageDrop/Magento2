<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Plugin\Frontend;

use MageDrop\Magento2\Model\Preview\State;
use Magento\Framework\View\Element\AbstractBlock;

/**
 * Block-HTML cache (e.g. Luma catalog.topnav, Hyvä topmenu_generic, 3600s)
 * keys on store/template/base URL only, so a menu rendered during preview would
 * be served to normal visitors and vice versa. Fold the preview vary token into
 * every block cache key while a preview is active.
 */
class BlockCacheKeyPlugin
{
    public function __construct(
        private State $state
    ) {
    }

    public function afterGetCacheKeyInfo(AbstractBlock $subject, $result)
    {
        $vary = $this->state->getVaryValue();
        if ($vary === null || !is_array($result)) {
            return $result;
        }

        $result['magedrop_preview'] = $vary;

        return $result;
    }
}
