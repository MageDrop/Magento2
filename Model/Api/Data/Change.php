<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Model\Api\Data;

use MageDrop\Magento2\Api\Data\ChangeInterface;
use MageDrop\Magento2\Model\Entity\Value;

class Change implements ChangeInterface
{
    private string $field = '';
    private string $type = Value::TYPE_TEXT;
    private ?string $value = null;
    private ?string $url = null;

    public static function fromValue(string $field, Value $value): self
    {
        $change = new self();
        $change->field = $field;
        $change->type = $value->type;
        $change->url = $value->meta['url'] ?? null;
        $change->value = match ($value->type) {
            Value::TYPE_INHERIT => null,
            Value::TYPE_JSON => (string) json_encode($value->value),
            default => (string) $value->value,
        };

        return $change;
    }

    public function toValue(): Value
    {
        return match ($this->type) {
            Value::TYPE_INHERIT => Value::inherit(),
            Value::TYPE_JSON => Value::json($this->value === null || $this->value === '' ? [] : json_decode($this->value, true)),
            Value::TYPE_IMAGE => Value::image((string) $this->value, $this->url),
            default => Value::text($this->value),
        };
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function setField(string $field): ChangeInterface
    {
        $this->field = $field;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): ChangeInterface
    {
        $this->type = $type;

        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): ChangeInterface
    {
        $this->value = $value;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): ChangeInterface
    {
        $this->url = $url;

        return $this;
    }
}
