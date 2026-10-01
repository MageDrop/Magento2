<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model;

/**
 * Request-scoped flag: "this save is a MageDrop deploy / rollback". Lets the
 * catalog revision observers record deploys made through the webapi without
 * also recording every third-party REST import.
 */
class ApplyContext
{
    private ?string $source = null;

    public function begin(string $source): void
    {
        $this->source = $source;
    }

    public function end(): void
    {
        $this->source = null;
    }

    public function isApplying(): bool
    {
        return $this->source !== null;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }
}
