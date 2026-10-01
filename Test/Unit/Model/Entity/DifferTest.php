<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Test\Unit\Model\Entity;

use MageDrop\Magento2\Model\Entity\AbstractAdapter;
use MageDrop\Magento2\Model\Entity\Differ;
use MageDrop\Magento2\Model\Entity\Section\SectionHandlerInterface;
use MageDrop\Magento2\Model\Entity\Value;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;

class DifferTest extends TestCase
{
    public function testOnlyChangedFieldsAreReported(): void
    {
        $entity = new DataObject(['name' => 'Sofas', 'description' => 'Old', 'is_active' => '1']);
        $post = ['name' => 'Sofas', 'description' => 'New', 'is_active' => true, 'form_key' => 'x'];

        $changes = (new Differ())->diff($this->adapter([]), $entity, $post, 0);

        $this->assertCount(1, $changes);
        $this->assertSame('description', $changes[0]['field']);
        $this->assertSame('Old', $changes[0]['original']->value);
        $this->assertSame('New', $changes[0]['staged']->value);
    }

    public function testInheritIsSkippedWhenTheStoreAlreadyInherits(): void
    {
        $entity = new DataObject(['name' => 'Sofas', 'description' => 'Default desc']);
        $post = ['name' => 'Sofas', 'description' => 'Default desc', 'use_default' => ['description' => '1', 'name' => '1']];

        // "name" is overridden at store 2, "description" is not
        $changes = (new Differ())->diff($this->adapter(['name']), $entity, $post, 2);

        $this->assertCount(1, $changes);
        $this->assertSame('name', $changes[0]['field']);
        $this->assertTrue($changes[0]['staged']->isInherit());
    }

    private function adapter(array $overriddenFields): AbstractAdapter
    {
        $section = new class ($overriddenFields) implements SectionHandlerInterface {
            public function __construct(private array $overridden)
            {
            }

            public function extract(array $post, DataObject $entity, int $storeId): array
            {
                $values = [];
                foreach ($post as $field => $raw) {
                    if ($field === 'form_key' || $field === 'use_default') {
                        continue;
                    }
                    if ($storeId > 0 && !empty($post['use_default'][$field])) {
                        $values[$field] = Value::inherit();
                        continue;
                    }
                    $values[$field] = Value::text($raw);
                }

                return $values;
            }

            public function current(DataObject $entity, int $storeId, ?array $fields = null): array
            {
                $values = [];
                foreach ($fields ?? array_keys($entity->getData()) as $field) {
                    $values[$field] = Value::text($entity->getData($field));
                }

                return $values;
            }

            public function handles(string $field, DataObject $entity): bool
            {
                return true;
            }

            public function isScopable(DataObject $entity, string $field): bool
            {
                return true;
            }

            public function isOverridden(DataObject $entity, string $field, int $storeId): bool
            {
                return in_array($field, $this->overridden, true);
            }

            public function apply(DataObject $entity, array $values, int $storeId): void
            {
            }

            public function overlay(DataObject $entity, array $values): void
            {
            }

            public function toFormData(array $data, array $values): array
            {
                return $data;
            }
        };

        return new class ($section) extends AbstractAdapter {
            public function __construct(SectionHandlerInterface $section)
            {
                parent::__construct('test', 'Test', 'Tests', 'id', 'test/edit', [$section], true);
            }

            public function load(string $entityId, int $storeId): DataObject
            {
                return new DataObject();
            }

            public function getTitle(DataObject $entity): ?string
            {
                return null;
            }

            public function save(DataObject $entity, int $storeId, array $appliedFields = []): void
            {
            }
        };
    }
}
