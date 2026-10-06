<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Internal;

/**
 * Input checks for values a caller hands to the client. Messages name the input, never its value.
 *
 * @internal
 */
final class Assert
{
    private function __construct()
    {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function notBlank(string $label, string $value): void
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('The %s must not be blank.', $label));
        }
    }

    /**
     * Checks at runtime what a list<...> PHPDoc type only promises to static analysis.
     *
     * @param array<mixed> $items
     * @param class-string $class
     *
     * @throws \InvalidArgumentException
     */
    public static function listOf(string $label, array $items, string $class): void
    {
        if (!array_is_list($items)) {
            throw new \InvalidArgumentException(sprintf('The %s must be a list.', $label));
        }
        foreach ($items as $item) {
            if (!$item instanceof $class) {
                throw new \InvalidArgumentException(sprintf('Every entry of the %s must be a %s.', $label, $class));
            }
        }
    }
}
