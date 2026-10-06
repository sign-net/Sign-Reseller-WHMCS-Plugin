<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Internal;

/**
 * Typed, fail-fast reads from a decoded JSON object. Unknown keys are ignored; a missing or
 * mistyped required field throws InvalidPayloadException naming the field. A nullable field may
 * also be absent.
 *
 * @internal
 */
final class Payload
{
    /**
     * @param array<mixed> $data
     */
    private function __construct(private readonly array $data, private readonly string $path)
    {
    }

    /**
     * @param array<mixed> $data
     */
    public static function of(array $data): self
    {
        return new self($data, '');
    }

    public function string(string $key): string
    {
        $value = $this->data[$key] ?? null;
        if (!is_string($value)) {
            throw $this->invalid($key, 'a string');
        }

        return $value;
    }

    public function stringOrNull(string $key): ?string
    {
        $value = $this->data[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw $this->invalid($key, 'a string or null');
        }

        return $value;
    }

    public function int(string $key): int
    {
        $value = $this->data[$key] ?? null;
        if (!is_int($value)) {
            throw $this->invalid($key, 'an integer');
        }

        return $value;
    }

    public function intOrNull(string $key): ?int
    {
        $value = $this->data[$key] ?? null;
        if ($value !== null && !is_int($value)) {
            throw $this->invalid($key, 'an integer or null');
        }

        return $value;
    }

    public function bool(string $key): bool
    {
        $value = $this->data[$key] ?? null;
        if (!is_bool($value)) {
            throw $this->invalid($key, 'a boolean');
        }

        return $value;
    }

    public function object(string $key): self
    {
        return new self($this->objectData($key), $this->pathOf($key) . '.');
    }

    public function objectOrNull(string $key): ?self
    {
        return ($this->data[$key] ?? null) === null ? null : $this->object($key);
    }

    /**
     * @return array<string, int>
     */
    public function intMap(string $key): array
    {
        $map = [];
        foreach ($this->objectData($key) as $name => $value) {
            if (!is_int($value)) {
                throw $this->invalid($key . '.' . $name, 'an integer');
            }
            $map[(string) $name] = $value;
        }

        return $map;
    }

    /**
     * @template T
     *
     * @param callable(array<mixed>): T $decoder
     *
     * @return T
     */
    public function decode(string $key, callable $decoder): mixed
    {
        $data = $this->objectData($key);

        return $this->decodeAt($this->pathOf($key), static fn (): mixed => $decoder($data));
    }

    /**
     * @template T
     *
     * @param callable(array<mixed>): T $decoder
     *
     * @return T|null
     */
    public function decodeOrNull(string $key, callable $decoder): mixed
    {
        return ($this->data[$key] ?? null) === null ? null : $this->decode($key, $decoder);
    }

    /**
     * @template T
     *
     * @param callable(array<mixed>): T $decoder
     *
     * @return list<T>
     */
    public function decodeList(string $key, callable $decoder): array
    {
        $items = $this->data[$key] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw $this->invalid($key, 'a list');
        }

        $decoded = [];
        foreach ($items as $index => $item) {
            $itemPath = $this->pathOf($key) . '.' . $index;
            if (!is_array($item)) {
                throw InvalidPayloadException::forField($itemPath, 'an object');
            }
            $decoded[] = $this->decodeAt($itemPath, static fn (): mixed => $decoder($item));
        }

        return $decoded;
    }

    /**
     * @return array<mixed>
     */
    private function objectData(string $key): array
    {
        $value = $this->data[$key] ?? null;
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw $this->invalid($key, 'an object');
        }

        return $value;
    }

    /**
     * @template T
     *
     * @param callable(): T $decode
     *
     * @return T
     */
    private function decodeAt(string $path, callable $decode): mixed
    {
        try {
            return $decode();
        } catch (InvalidPayloadException $exception) {
            throw $exception->within($path);
        }
    }

    private function pathOf(string $key): string
    {
        return $this->path . $key;
    }

    private function invalid(string $key, string $expectation): InvalidPayloadException
    {
        return InvalidPayloadException::forField($this->pathOf($key), $expectation);
    }
}
