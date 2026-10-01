<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Api\Data;

use MageDrop\Magento2\Api\Data\ApplyResultInterface;

class ApplyResult implements ApplyResultInterface
{
    /**
     * @param \MageDrop\Magento2\Api\Data\ChangeInterface[] $previous
     * @param string[] $appliedFields
     */
    public function __construct(
        private ?string $title = null,
        private array $previous = [],
        private array $appliedFields = []
    ) {
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getPrevious(): array
    {
        return $this->previous;
    }

    public function getAppliedFields(): array
    {
        return $this->appliedFields;
    }
}
