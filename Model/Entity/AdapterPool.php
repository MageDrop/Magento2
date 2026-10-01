<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity;

use Magento\Framework\Exception\LocalizedException;

class AdapterPool
{
    /**
     * @param AdapterInterface[] $adapters keyed by entity type code
     */
    public function __construct(
        private array $adapters = []
    ) {
    }

    public function has(string $code): bool
    {
        return isset($this->adapters[$code]);
    }

    /**
     * @throws LocalizedException
     */
    public function get(string $code): AdapterInterface
    {
        if (!isset($this->adapters[$code])) {
            throw new LocalizedException(__('Unknown MageDrop entity type "%1".', $code));
        }

        return $this->adapters[$code];
    }

    /**
     * @return AdapterInterface[]
     */
    public function all(): array
    {
        return $this->adapters;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function describe(): array
    {
        return array_values(array_map(fn (AdapterInterface $a) => $a->describe(), $this->adapters));
    }
}
