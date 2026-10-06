<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin;

use SignNet\ResellerApi\Auth\ApiKey;
use SignNet\ResellerApi\ResellerClient;
use SignNet\Whmcs\ClientFactory;
use WHMCS\Database\Capsule;

/**
 * The Sign.net server the addon's pages talk to. The client is built on first use, so a page can
 * still show what WHMCS knows when the server record is unusable.
 */
final class SignNetServer
{
    private ?ResellerClient $client = null;

    public function __construct(public readonly int $serverId)
    {
    }

    /**
     * @throws \InvalidArgumentException When the server record holds no usable address or key.
     */
    public function client(): ResellerClient
    {
        return $this->client ??= ClientFactory::fromServerId($this->serverId);
    }

    /**
     * "live" or "test", read from the key in the server record without revealing the key.
     *
     * @throws \InvalidArgumentException When the key is missing or malformed.
     */
    public function keyEnvironment(): string
    {
        $password = Capsule::table('tblservers')->where('id', $this->serverId)->value('password');

        return ApiKey::parse(decrypt((string) $password))->environment;
    }
}
