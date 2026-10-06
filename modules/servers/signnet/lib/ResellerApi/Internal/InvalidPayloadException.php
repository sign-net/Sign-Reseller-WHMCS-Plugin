<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Internal;

/**
 * A decoded response lacks a required field or holds a value of the wrong type. The message
 * names the field and the expected type, never the value.
 *
 * @internal
 */
final class InvalidPayloadException extends \UnexpectedValueException
{
    private function __construct(public readonly string $field, private readonly string $expectation)
    {
        parent::__construct(sprintf('field "%s" must be %s', $field, $expectation));
    }

    public static function forField(string $field, string $expectation): self
    {
        return new self($field, $expectation);
    }

    public function within(string $parentField): self
    {
        return new self($parentField . '.' . $this->field, $this->expectation);
    }
}
