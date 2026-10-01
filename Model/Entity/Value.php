<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Entity;

/**
 * Typed value envelope shared by every MageDrop boundary (admin staging, SaaS
 * API, webapi apply, preview overlay, revisions).
 *
 *  - text    : scalar rendered as a string ("1"/"0" for booleans)
 *  - json    : nested structure (gallery, options, product positions, ...)
 *  - image   : media path (e.g. "/media/catalog/category/x.jpg"), meta.url for display
 *  - inherit : "Use Default Value" — remove the store-level override on apply
 */
final class Value
{
    public const TYPE_TEXT = 'text';
    public const TYPE_JSON = 'json';
    public const TYPE_IMAGE = 'image';
    public const TYPE_INHERIT = 'inherit';

    private function __construct(
        public readonly string $type,
        public readonly mixed $value,
        public readonly array $meta = []
    ) {
    }

    public static function text(mixed $value): self
    {
        return new self(self::TYPE_TEXT, self::normaliseScalar($value));
    }

    public static function json(mixed $value): self
    {
        return new self(self::TYPE_JSON, self::canonicalise($value));
    }

    public static function image(string $path, ?string $url = null): self
    {
        return new self(self::TYPE_IMAGE, $path, $url !== null && $url !== '' ? ['url' => $url] : []);
    }

    public static function inherit(): self
    {
        return new self(self::TYPE_INHERIT, null);
    }

    /**
     * Build from the wire representation {type, value, url?}.
     */
    public static function fromArray(array $data): self
    {
        $type = (string) ($data['type'] ?? self::TYPE_TEXT);
        $value = $data['value'] ?? null;

        return match ($type) {
            self::TYPE_INHERIT => self::inherit(),
            self::TYPE_JSON => self::json($value),
            self::TYPE_IMAGE => self::image((string) ($value ?? ''), isset($data['url']) ? (string) $data['url'] : null),
            default => self::text($value),
        };
    }

    public function toArray(): array
    {
        $out = ['type' => $this->type, 'value' => $this->value];
        if ($this->meta) {
            $out += $this->meta;
        }

        return $out;
    }

    public function isInherit(): bool
    {
        return $this->type === self::TYPE_INHERIT;
    }

    public function equals(Value $other): bool
    {
        if ($this->type !== $other->type) {
            // An empty image and an empty text are the same "nothing" for diff purposes.
            if ($this->isEmptyLike() && $other->isEmptyLike()) {
                return true;
            }

            return false;
        }

        return match ($this->type) {
            self::TYPE_INHERIT => true,
            self::TYPE_JSON => json_encode($this->value) === json_encode($other->value),
            default => self::sameText((string) $this->value, (string) $other->value),
        };
    }

    /**
     * Decimal attributes load as "2025.000000" but the form posts "2025.00".
     */
    private static function sameText(string $a, string $b): bool
    {
        $a = trim($a);
        $b = trim($b);
        if ($a === $b) {
            return true;
        }

        return is_numeric($a) && is_numeric($b) && (float) $a === (float) $b;
    }

    public function isEmptyLike(): bool
    {
        return match ($this->type) {
            self::TYPE_INHERIT => false,
            self::TYPE_JSON => $this->value === null || $this->value === [] ,
            default => trim((string) $this->value) === '',
        };
    }

    /**
     * Value in the shape a Magento model expects from setData().
     */
    public function forModel(): mixed
    {
        return $this->type === self::TYPE_INHERIT ? null : $this->value;
    }

    private static function normaliseScalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value) || is_object($value)) {
            return (string) json_encode($value);
        }

        return (string) $value;
    }

    /**
     * Sort associative arrays recursively so equal structures encode identically.
     */
    private static function canonicalise(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_keys($value) === range(0, count($value) - 1);
        if (!$isList) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $k => $v) {
            $value[$k] = self::canonicalise($v);
        }

        return $value;
    }
}
