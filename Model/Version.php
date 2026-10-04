<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model;

final class Version
{
    public const VERSION = '2.0.2';

    /** Protocol features the SaaS can rely on. */
    public const CAPABILITIES = ['apply', 'entity_state', 'scoped_changes', 'value_envelopes', 'delta_staging', 'admin_open'];
}
