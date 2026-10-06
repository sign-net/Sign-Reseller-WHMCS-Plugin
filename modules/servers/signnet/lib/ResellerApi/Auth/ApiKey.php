<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Auth;

/**
 * A reseller API key, "snk_<live|test>_<32 hex key id>_<secret>".
 *
 * The secret never appears in string conversions, var_dump() or print_r(); reveal() is the one
 * way to read the full key.
 */
final class ApiKey
{
    private const PATTERN = '/^snk_(live|test)_([0-9a-f]{32})_([A-Za-z0-9]+)$/D';

    /**
     * @param 'live'|'test' $environment
     */
    private function __construct(
        public readonly string $environment,
        public readonly string $keyId,
        private readonly string $plaintext,
    ) {
    }

    /**
     * @throws \InvalidArgumentException When the key is not in the snk_ format.
     */
    public static function parse(#[\SensitiveParameter] string $key): self
    {
        $key = trim($key);
        if (preg_match(self::PATTERN, $key, $matches) !== 1) {
            throw new \InvalidArgumentException(
                'The Sign.net API key is malformed: expected "snk_live_" or "snk_test_", '
                . 'a 32-character key id and the secret, as shown when the key was created.',
            );
        }

        return new self($matches[1] === 'live' ? 'live' : 'test', $matches[2], $key);
    }

    public function reveal(): string
    {
        return $this->plaintext;
    }

    public function masked(): string
    {
        return 'snk_' . $this->environment . '_' . $this->keyId . '_****';
    }

    public function __toString(): string
    {
        return $this->masked();
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['environment' => $this->environment, 'keyId' => $this->keyId, 'secret' => '****'];
    }
}
