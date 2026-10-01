<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity\Section;

/**
 * Optional for section handlers that stage structured (json) values: tells the
 * MageDrop dashboard how to diff them, published on handshake per entity type.
 *
 * Rule per field: [
 *   'id'          => ['key', ...],   // keys identifying a record (first present wins)
 *   'label'       => ['key', ...],   // keys used to label a record
 *   'labelFormat' => '%s: %s',       // optional sprintf over the label keys
 *   'hide'        => ['key', ...],   // keys not worth showing
 *   'children'    => ['key' => 'nested_list_key', 'id' => [...], 'label' => [...]],
 * ]
 */
interface DescribesValuesInterface
{
    /**
     * @return array<string, array<string, mixed>> field => rule
     */
    public function describeValues(): array;
}
