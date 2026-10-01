<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Inventory;

use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;

/**
 * Thin, lazily-resolved facade over Magento Multi-Source Inventory so the
 * module works on stores that have removed the Inventory modules.
 */
class Msi
{
    private ?bool $available = null;

    public function __construct(
        private ModuleManager $moduleManager,
        private ObjectManagerInterface $objectManager
    ) {
    }

    public function isAvailable(): bool
    {
        if ($this->available === null) {
            $this->available = $this->moduleManager->isEnabled('Magento_InventoryCatalog')
                && $this->moduleManager->isEnabled('Magento_InventoryApi')
                && interface_exists(\Magento\InventoryCatalogApi\Model\SourceItemsProcessorInterface::class);
        }

        return $this->available;
    }

    public function isMultiSource(): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        return !$this->objectManager->get(\Magento\InventoryCatalogApi\Model\IsSingleSourceModeInterface::class)->execute();
    }

    public function isManagedType(string $typeId): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        return (bool) $this->objectManager
            ->get(\Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface::class)
            ->execute($typeId);
    }

    public function defaultSourceCode(): string
    {
        return (string) $this->objectManager
            ->get(\Magento\InventoryCatalogApi\Api\DefaultSourceProviderInterface::class)
            ->getCode();
    }

    /**
     * @return array<int, array{source_code: string, quantity: float, status: int}>
     */
    public function getSourceItems(string $sku): array
    {
        $rows = [];
        foreach ($this->objectManager->get(\Magento\InventoryApi\Api\GetSourceItemsBySkuInterface::class)->execute($sku) as $item) {
            $rows[] = [
                'source_code' => (string) $item->getSourceCode(),
                'quantity' => (float) $item->getQuantity(),
                'status' => (int) $item->getStatus(),
            ];
        }

        return $rows;
    }

    /**
     * Save / replace the source items of a SKU (missing sources are unassigned).
     *
     * @param array<int, array{source_code: string, quantity: float|int, status: int}> $rows
     */
    public function saveSourceItems(string $sku, array $rows): void
    {
        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'sku' => $sku,
                'source_code' => (string) $row['source_code'],
                'quantity' => $row['quantity'],
                'status' => (int) $row['status'],
            ];
        }
        $this->objectManager
            ->get(\Magento\InventoryCatalogApi\Model\SourceItemsProcessorInterface::class)
            ->execute($sku, $data);
    }

    /**
     * Single-source mode: mirror quantity / in-stock onto the default source,
     * as MSI's own admin observer does after a product save.
     */
    public function syncDefaultSource(string $sku, ?float $qty, ?int $inStock): void
    {
        if (!$this->isAvailable() || $this->isMultiSource()) {
            return;
        }
        $default = $this->defaultSourceCode();
        $rows = [];
        foreach ($this->getSourceItems($sku) as $row) {
            if ($row['source_code'] !== $default) {
                $rows[] = $row;
            }
        }
        $current = null;
        foreach ($this->getSourceItems($sku) as $row) {
            if ($row['source_code'] === $default) {
                $current = $row;
            }
        }
        $rows[] = [
            'source_code' => $default,
            'quantity' => $qty ?? ($current['quantity'] ?? 0),
            'status' => $inStock ?? ($current['status'] ?? 0),
        ];
        $this->saveSourceItems($sku, $rows);
    }
}
