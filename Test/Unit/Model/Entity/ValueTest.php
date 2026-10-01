<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Test\Unit\Model\Entity;

use MageDrop\Magento2\Model\Entity\Value;
use PHPUnit\Framework\TestCase;

class ValueTest extends TestCase
{
    public function testTextNormalisesScalars(): void
    {
        $this->assertSame('1', Value::text(true)->value);
        $this->assertSame('0', Value::text(false)->value);
        $this->assertSame('', Value::text(null)->value);
        $this->assertSame('12', Value::text(12)->value);
    }

    public function testJsonIsCanonicalisedForComparison(): void
    {
        $a = Value::json(['b' => 1, 'a' => ['y' => 2, 'x' => 1]]);
        $b = Value::json(['a' => ['x' => 1, 'y' => 2], 'b' => 1]);

        $this->assertTrue($a->equals($b));
        $this->assertSame('{"a":{"x":1,"y":2},"b":1}', json_encode($a->value));
    }

    public function testListsKeepTheirOrder(): void
    {
        $this->assertFalse(Value::json([1, 2, 3])->equals(Value::json([3, 2, 1])));
    }

    public function testEqualityIgnoresWhitespaceAndTreatsEmptyTypesAlike(): void
    {
        $this->assertTrue(Value::text(' x ')->equals(Value::text('x')));
        $this->assertTrue(Value::image('')->equals(Value::text('')));
        $this->assertFalse(Value::inherit()->equals(Value::text('')));
        $this->assertTrue(Value::inherit()->equals(Value::inherit()));
    }

    public function testRoundTripsThroughArray(): void
    {
        $image = Value::image('/media/catalog/category/x.jpg', 'https://shop.test/media/catalog/category/x.jpg');
        $back = Value::fromArray($image->toArray());

        $this->assertSame(Value::TYPE_IMAGE, $back->type);
        $this->assertSame('/media/catalog/category/x.jpg', $back->value);
        $this->assertSame('https://shop.test/media/catalog/category/x.jpg', $back->meta['url']);

        $this->assertTrue(Value::fromArray(['type' => 'inherit', 'value' => null])->isInherit());
        $this->assertNull(Value::inherit()->forModel());
    }
}
