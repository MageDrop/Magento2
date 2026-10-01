<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Api\Data;

interface ApplyResultInterface
{
    /**
     * @return string|null
     */
    public function getTitle(): ?string;

    /**
     * Values as they were before apply, for rollback.
     *
     * @return \MageDrop\Magento2\Api\Data\ChangeInterface[]
     */
    public function getPrevious(): array;

    /**
     * @return string[]
     */
    public function getAppliedFields(): array;
}
