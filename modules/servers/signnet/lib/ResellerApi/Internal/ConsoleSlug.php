<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Internal;

use SignNet\ResellerApi\Auth\AccessToken;
use SignNet\ResellerApi\Auth\TokenProvider;
use SignNet\ResellerApi\Exception\ApiException;

/**
 * The reseller's console slug: the host its /console/{slug}/reseller endpoints are addressed by.
 * A key can learn it only from GET /console/dashboard, so it is learnt once and kept with the
 * access token; a new token learns it again.
 *
 * @internal
 */
final class ConsoleSlug
{
    private const DASHBOARD_PATH = '/console/dashboard';
    private const HOST_LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
    private const HOST_PATTERN = '/^(?=.{1,253}$)' . self::HOST_LABEL . '(?:\.' . self::HOST_LABEL . ')*$/D';

    public function __construct(
        private readonly AuthorizedSender $sender,
        private readonly TokenProvider $tokens,
        private readonly int $timeoutSeconds,
    ) {
    }

    /**
     * @throws ApiException A failure that is not a refusal is reported as never sent: the call that
     *     needed the slug did not leave, which is what provisioning has to know.
     */
    public function current(): string
    {
        $token = BeforeSending::run('get an access token', fn (): AccessToken => $this->tokens->token());

        return $token->slug ?? $this->learn();
    }

    /**
     * Learns the slug again, for a refusal that a renamed reseller host would explain.
     *
     * @return string|null The new slug; null when it is unchanged or cannot be learnt.
     */
    public function renamedFrom(string $used): ?string
    {
        try {
            $slug = $this->learn();
        } catch (ApiException) {
            return null;
        }

        return $slug === $used ? null : $slug;
    }

    /**
     * @throws ApiException
     */
    private function learn(): string
    {
        $slug = BeforeSending::run(
            'learn the reseller console address',
            fn (): string => $this->sender->send('GET', self::DASHBOARD_PATH, null, $this->timeoutSeconds)
                ->data(self::slugOf(...)),
        );
        $this->tokens->rememberSlug($slug);

        return $slug;
    }

    /**
     * @param array<mixed> $data
     */
    private static function slugOf(array $data): string
    {
        $slug = Payload::of($data)->stringOrNull('slug');
        if ($slug === null || preg_match(self::HOST_PATTERN, $slug) !== 1) {
            throw InvalidPayloadException::forField('slug', "the reseller's host");
        }

        return $slug;
    }
}
