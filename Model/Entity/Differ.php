<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity;

use Magento\Framework\DataObject;

class Differ
{
    /**
     * Compare what the admin form would save against the live entity.
     *
     * @return array<int, array{field: string, original: Value, staged: Value}>
     */
    public function diff(AdapterInterface $adapter, DataObject $entity, array $post, int $storeId): array
    {
        $staged = $adapter->extract($entity, $post, $storeId);
        if (!$staged) {
            return [];
        }

        $current = $adapter->current($entity, $storeId, array_keys($staged));
        $changes = [];

        foreach ($staged as $field => $stagedValue) {
            $original = $current[$field] ?? Value::text('');

            if ($stagedValue->isInherit()) {
                // Nothing to do if the store already inherits this field
                if (!$adapter->isOverridden($entity, $field, $storeId)) {
                    continue;
                }
            } elseif ($stagedValue->equals($original) || $this->isUnsetZero($original, $stagedValue)) {
                continue;
            }

            $changes[] = ['field' => $field, 'original' => $original, 'staged' => $stagedValue];
        }

        return $changes;
    }

    /**
     * A select whose stored 0 matches none of its options (often a hidden field) posts '',
     * which would stage a change nobody made.
     */
    private function isUnsetZero(Value $original, Value $staged): bool
    {
        return $original->type === Value::TYPE_TEXT
            && $staged->type === Value::TYPE_TEXT
            && trim((string) $original->value) === '0'
            && $staged->isEmptyLike();
    }
}
