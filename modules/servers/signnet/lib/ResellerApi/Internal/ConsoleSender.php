<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Internal;

use SignNet\ResellerApi\Exception\ApiException;
use SignNet\ResellerApi\Exception\ErrorCode;
use SignNet\ResellerApi\Exception\ForbiddenException;

/**
 * Sends reseller calls to the console under the reseller's slug: /console/{slug}/reseller/...
 *
 * A 403 forbidden may mean Sign.net renamed the reseller's host since the slug was learnt, so the
 * slug is learnt again and, when it changed, the call is sent once more under it. The console
 * refuses before any handler runs, so the resend cannot repeat a side effect.
 *
 * @internal
 */
final class ConsoleSender
{
    public function __construct(private readonly AuthorizedSender $sender, private readonly ConsoleSlug $slugs)
    {
    }

    /**
     * @param string $path After /console/{slug}/reseller.
     * @param array<string, mixed>|null $payload
     * @param bool $isReadOnly A POST that only reads; retried as a GET is.
     *
     * @throws ApiException
     */
    public function send(
        string $method,
        string $path,
        ?array $payload,
        int $timeoutSeconds,
        bool $isReadOnly = false,
    ): Exchange {
        $consolePath = static fn (string $slug): string => '/console/' . rawurlencode($slug) . '/reseller' . $path;
        $slug = $this->slugs->current();
        try {
            return $this->sender->send($method, $consolePath($slug), $payload, $timeoutSeconds, $isReadOnly);
        } catch (ForbiddenException $refusal) {
            $renamed = $refusal->errorCode === ErrorCode::FORBIDDEN ? $this->slugs->renamedFrom($slug) : null;
            if ($renamed === null) {
                throw $refusal;
            }
        }

        return $this->sender->send($method, $consolePath($renamed), $payload, $timeoutSeconds, $isReadOnly);
    }
}
