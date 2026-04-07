<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Api;

interface PingInterface
{
    /**
     * Test the module's connection to the MageDrop SaaS.
     *
     * @return string
     */
    public function ping(): string;
}
