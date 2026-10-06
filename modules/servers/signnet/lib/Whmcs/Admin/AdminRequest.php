<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin;

/**
 * One request to the addon's pages: which page and action, and what the administrator submitted.
 *
 * WHMCS HTML-encodes request variables before a module sees them, so values are decoded here and
 * what the administrator typed ("Docs & Seats") is what reaches Sign.net.
 */
final class AdminRequest
{
    /**
     * @param array<mixed> $query
     * @param array<mixed> $post
     */
    public function __construct(
        private readonly string $moduleLink,
        private readonly string $method,
        private readonly array $query,
        private readonly array $post,
    ) {
    }

    public function isPost(): bool
    {
        return strtoupper($this->method) === 'POST';
    }

    public function page(): string
    {
        return $this->query('page');
    }

    public function action(): string
    {
        return $this->query('action');
    }

    public function query(string $name): string
    {
        return self::text($this->query[$name] ?? null);
    }

    public function post(string $name): string
    {
        return self::text($this->post[$name] ?? null);
    }

    public function posted(string $name): bool
    {
        return array_key_exists($name, $this->post);
    }

    /**
     * A submitted group of fields such as items[seats][included], as name => trimmed text.
     *
     * @return array<array-key, array<array-key, string>>
     */
    public function postGroup(string $name): array
    {
        $group = [];
        foreach (is_array($this->post[$name] ?? null) ? $this->post[$name] : [] as $key => $fields) {
            $group[$key] = is_array($fields) ? array_map(self::text(...), $fields) : [];
        }

        return $group;
    }

    /**
     * @return list<int> The submitted values of a multi-value field such as products[].
     */
    public function postIds(string $name): array
    {
        $values = is_array($this->post[$name] ?? null) ? $this->post[$name] : [];

        return array_values(array_filter(
            array_map(static fn (mixed $value): int => (int) self::text($value), $values),
            static fn (int $id): bool => $id > 0,
        ));
    }

    /**
     * A link to one of the addon's pages.
     *
     * @param array<string, string|int> $parameters
     */
    public function url(string $page, array $parameters = []): string
    {
        return $this->moduleLink . '&' . http_build_query(['page' => $page] + $parameters);
    }

    private static function text(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) ? trim(htmlspecialchars_decode($value, ENT_QUOTES)) : '';
    }
}
