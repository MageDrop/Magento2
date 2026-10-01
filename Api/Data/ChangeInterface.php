<?php

declare(strict_types=1);

namespace MageDrop\Magento2\Api\Data;

/**
 * One field value crossing the MageDrop webapi. `value` is the raw string for
 * text/image types, a JSON-encoded string for json, and null for inherit.
 */
interface ChangeInterface
{
    /**
     * @return string
     */
    public function getField(): string;

    /**
     * @param string $field
     * @return $this
     */
    public function setField(string $field): self;

    /**
     * @return string
     */
    public function getType(): string;

    /**
     * @param string $type
     * @return $this
     */
    public function setType(string $type): self;

    /**
     * @return string|null
     */
    public function getValue(): ?string;

    /**
     * @param string|null $value
     * @return $this
     */
    public function setValue(?string $value): self;

    /**
     * Display URL for image values.
     *
     * @return string|null
     */
    public function getUrl(): ?string;

    /**
     * @param string|null $url
     * @return $this
     */
    public function setUrl(?string $url): self;
}
