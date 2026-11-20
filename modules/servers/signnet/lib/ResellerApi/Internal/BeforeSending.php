<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Internal;

use SignNet\ResellerApi\Exception\ApiException;
use SignNet\ResellerApi\Exception\ServerException;
use SignNet\ResellerApi\Exception\TransportException;

/**
 * Runs what a call needs before it can be sent: an access token, or the console address.
 *
 * When that step fails with a server error or no answer, the call itself never left, so the
 * failure is reported as a transport failure with nothing sent. A caller that must not repeat a
 * write can then tell it from a lost answer. A refusal, such as a refused key or a rate limit,
 * already says that nothing was done, and passes through as it is.
 *
 * @internal
 */
final class BeforeSending
{
    /**
     * @template T
     *
     * @param string $step What the step does, completing "Could not …".
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws ApiException
     */
    public static function run(string $step, callable $work): mixed
    {
        try {
            return $work();
        } catch (ServerException | TransportException $failure) {
            throw new TransportException(
                sprintf('Could not %s: %s', $step, $failure->getMessage()),
                false,
                $failure->requestId,
                $failure,
            );
        }
    }
}
