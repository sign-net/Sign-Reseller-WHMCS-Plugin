<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Auth;

final class AccessToken
{
    public const DEFAULT_REFRESH_MARGIN_SECONDS = 300;

    /**
     * @param list<string> $scopes
     * @param int $expiresAt Unix time in seconds.
     * @param string|null $slug The reseller's console slug, once learnt; it belongs to the key, so
     *     it is kept with the key's token to spare every process the lookup.
     */
    public function __construct(
        #[\SensitiveParameter]
        public readonly string $token,
        public readonly array $scopes,
        public readonly int $expiresAt,
        public readonly ?string $slug = null,
    ) {
    }

    public function withSlug(string $slug): self
    {
        return new self($this->token, $this->scopes, $this->expiresAt, $slug);
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * Whether the token stays valid for more than $marginSeconds after $now.
     */
    public function isUsable(int $now, int $marginSeconds = self::DEFAULT_REFRESH_MARGIN_SECONDS): bool
    {
        return $now < $this->expiresAt - $marginSeconds;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['token' => '****', 'scopes' => $this->scopes, 'expiresAt' => $this->expiresAt, 'slug' => $this->slug];
    }
}
