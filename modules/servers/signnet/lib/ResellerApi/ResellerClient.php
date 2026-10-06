<?php

declare(strict_types=1);

namespace SignNet\ResellerApi;

use SignNet\ResellerApi\Auth\TokenProvider;
use SignNet\ResellerApi\Auth\TokenStore;
use SignNet\ResellerApi\Exception\ApiException;
use SignNet\ResellerApi\Http\Transport;
use SignNet\ResellerApi\Internal\Assert;
use SignNet\ResellerApi\Internal\AuthorizedSender;
use SignNet\ResellerApi\Internal\ConsoleSender;
use SignNet\ResellerApi\Internal\ConsoleSlug;
use SignNet\ResellerApi\Internal\ErrorMapper;
use SignNet\ResellerApi\Internal\Exchange;
use SignNet\ResellerApi\Internal\Payload;
use SignNet\ResellerApi\Internal\RequestFactory;
use SignNet\ResellerApi\Internal\RequestSender;
use SignNet\ResellerApi\Model\AddUserResult;
use SignNet\ResellerApi\Model\Addon;
use SignNet\ResellerApi\Model\AddonInput;
use SignNet\ResellerApi\Model\AddonSummary;
use SignNet\ResellerApi\Model\AddonUpdate;
use SignNet\ResellerApi\Model\AllocationOutcome;
use SignNet\ResellerApi\Model\Assignment;
use SignNet\ResellerApi\Model\DomainSetup;
use SignNet\ResellerApi\Model\Package;
use SignNet\ResellerApi\Model\PackageInput;
use SignNet\ResellerApi\Model\PackageSummary;
use SignNet\ResellerApi\Model\PackageUpdate;
use SignNet\ResellerApi\Model\PortalUser;
use SignNet\ResellerApi\Model\PrivateLabel;
use SignNet\ResellerApi\Model\PrivateLabelSummary;
use SignNet\ResellerApi\Model\ProvisionRequest;
use SignNet\ResellerApi\Model\ProvisionResult;
use SignNet\ResellerApi\Model\Quota;
use SignNet\ResellerApi\Model\SuspensionState;
use SignNet\ResellerApi\Model\Usage;
use SignNet\ResellerApi\Support\Clock;
use SignNet\ResellerApi\Support\RateLimitInfo;
use SignNet\ResellerApi\Support\Sleeper;
use SignNet\ResellerApi\Support\SystemClock;
use SignNet\ResellerApi\Support\SystemSleeper;

/**
 * Sign.net's reseller API, which is the reseller console's own (/console/{slug}/reseller/*) called
 * with an API key's access token: one method per endpoint.
 *
 * Every method throws an ApiException subclass when the call fails and \InvalidArgumentException
 * when an argument is unusable before anything is sent.
 */
final class ResellerClient
{
    private const PERIOD_PATTERN = '/^\d{4}-(0[1-9]|1[0-2])$/D';

    /** The console caps a catalogue at 100 entries, so one page holds it all; more are still read. */
    private const SEARCH_PAGE_SIZE = 100;
    private const MAX_SEARCH_PAGES = 50;

    private readonly TokenProvider $tokens;
    private readonly AuthorizedSender $authorized;
    private readonly ConsoleSender $sender;

    public function __construct(
        private readonly ClientConfig $config,
        Transport $transport,
        TokenStore $tokenStore,
        ?Clock $clock = null,
        ?Sleeper $sleeper = null,
    ) {
        $clock ??= new SystemClock();
        $sleeper ??= new SystemSleeper();
        $this->tokens = new TokenProvider($config, $transport, $tokenStore, $clock, $sleeper);
        $this->authorized = new AuthorizedSender(
            new RequestFactory($config),
            new RequestSender($transport, new ErrorMapper($clock), $sleeper),
            $this->tokens,
            $clock,
        );
        $this->sender = new ConsoleSender(
            $this->authorized,
            new ConsoleSlug($this->authorized, $this->tokens, $config->timeout),
        );
    }

    /**
     * The scopes the API key grants, from the current access token (minted when none is stored).
     *
     * @return list<string>
     *
     * @throws ApiException
     */
    public function grantedScopes(): array
    {
        return $this->tokens->token()->scopes;
    }

    /**
     * @throws ApiException
     */
    public function provisionPrivateLabel(ProvisionRequest $request): ProvisionResult
    {
        return $this->call('POST', '/private-labels', $request->toArray(), $this->config->provisionTimeout)
            ->data(ProvisionResult::fromArray(...));
    }

    /**
     * @return list<PrivateLabelSummary>
     *
     * @throws ApiException
     */
    public function listPrivateLabels(): array
    {
        return $this->call('GET', '/private-labels')->data(
            static fn (array $data): array => Payload::of($data)
                ->decodeList('privateLabels', PrivateLabelSummary::fromArray(...)),
        );
    }

    /**
     * @throws ApiException
     * @throws \InvalidArgumentException When an argument is blank or unusable in a URL.
     */
    public function getPrivateLabel(string $tenantId): PrivateLabel
    {
        return $this->call('GET', self::path('/private-labels/%s', $tenantId))->data(PrivateLabel::fromArray(...));
    }

    /**
     * Soft-deletes the private label for good; its hostname can never be used again. Repeating
     * the call answers 403, because a deleted label leaves the reseller's subtree.
     *
     * @throws ApiException
     * @throws \InvalidArgumentException When an argument is blank or unusable in a URL.
     */
    public function deprovisionPrivateLabel(string $tenantId): void
    {
        $this->call('DELETE', self::path('/private-labels/%s', $tenantId))->requireOk();
    }

    /**
     * @param string|null $reason Shown to the reseller and Sign.net staff, never to the tenant's users.
     *
     * @throws ApiException
     * @throws \InvalidArgumentException When an argument is blank or unusable in a URL.
     */
    public function suspendPrivateLabel(string $tenantId, ?string $reason = null): SuspensionState
    {
        return $this->call('POST', self::path('/private-labels/%s/suspend', $tenantId), self::optional([
            'reason' => $reason,
        ]))->data(SuspensionState::fromArray(...));
    }

    /**
     * @throws ApiException
     * @throws \InvalidArgumentException When an argument is blank or unusable in a URL.
     */
    public function unsuspendPrivateLabel(string $tenantId): SuspensionState
    {
        return $this->call('POST', self::path('/private-labels/%s/unsuspend', $tenantId), [])
            ->data(SuspensionState::fromArray(...));
    }

    /**
     * Retries attaching the private label's hostname to the hosting platform.
     *
     * @throws ApiException
     * @throws \InvalidArgumentException When an argument is blank or unusable in a URL.
     */
    public function attachDomain(string $tenantId): DomainSetup
    {
        return $this->call('POST', self::path('/private-labels/%s/domain/attach', $tenantId), [])->data(
            static fn (array $data): DomainSetup => Payload::of($data)
                ->decode('domainSetup', DomainSetup::fromArray(...)),
        );
    }

    /**
     * @return list<PortalUser>
     *
     * @throws ApiException
     * @throws \InvalidArgumentException When an argument is blank or unusable in a URL.
     */
    public function listUsers(string $tenantId): array
    {
        return $this->call('GET', self::path('/private-labels/%s/members', $tenantId))->data(
            static fn (array $data): array => Payload::of($data)->decodeList('members', PortalUser::fromArray(...)),
        );
    }

    /**
     * Adds a member, or reuses the tenant's existing user with that email.
     *
     * @throws ApiException
     * @throws \InvalidArgumentException When an argument is blank or unusable in a URL.
     */
    public function addUser(string $tenantId, string $email, string $firstName, string $lastName): AddUserResult
    {
        Assert::notBlank('email', $email);
        Assert::notBlank('first name', $firstName);
        Assert::notBlank('last name', $lastName);

        return $this->call('POST', self::path('/private-labels/%s/users', $tenantId), [
            'email' => $email,
            'firstName' => $firstName,
            'lastName' => $lastName,
        ])->data(AddUserResult::fromArray(...));
    }

    /**
     * @throws ApiException
     * @throws \InvalidArgumentException When an argument is blank or unusable in a URL.
     */
    public function removeUser(string $tenantId, string $userId): void
    {
        $this->call('DELETE', self::path('/private-labels/%s/users/%s', $tenantId, $userId))->requireOk();
    }

    /**
     * Sends the user a new set-password email; allowed once per user every five minutes.
     *
     * @throws ApiException
     * @throws \InvalidArgumentException When an argument is blank or unusable in a URL.
     */
    public function resendInvite(string $tenantId, string $userId): void
    {
        $this->call('POST', self::path('/private-labels/%s/users/%s/invite', $tenantId, $userId), [])->requireOk();
    }

    /**
     * @return list<PackageSummary>
     *
     * @throws ApiException
     */
    public function listPackages(): array
    {
        return $this->search('/billing/packages/search', 'packages', PackageSummary::fromArray(...));
    }

    /**
     * @throws ApiException
     * @throws \InvalidArgumentException When the package id is blank.
     */
    public function getPackage(string $packageId): Package
    {
        Assert::notBlank('package id', $packageId);

        return $this->call('POST', '/billing/packages/get', ['id' => $packageId], isReadOnly: true)
            ->data(Package::fromArray(...));
    }

    /**
     * @return string The new package id.
     *
     * @throws ApiException
     */
    public function createPackage(PackageInput $package): string
    {
        return $this->call('POST', '/billing/packages', $package->toArray())->data(
            static fn (array $data): string => Payload::of($data)->string('id'),
        );
    }

    /**
     * @throws ApiException
     * @throws \InvalidArgumentException When the package id is blank.
     */
    public function updatePackage(string $packageId, PackageUpdate $update): void
    {
        Assert::notBlank('package id', $packageId);

        $this->call('POST', '/billing/packages/update', ['id' => $packageId] + $update->toArray())->requireOk();
    }

    /**
     * @return list<AddonSummary>
     *
     * @throws ApiException
     */
    public function listAddons(): array
    {
        return $this->search('/billing/addons/search', 'addons', AddonSummary::fromArray(...));
    }

    /**
     * @throws ApiException
     * @throws \InvalidArgumentException When the add-on id is blank.
     */
    public function getAddon(string $addonId): Addon
    {
        Assert::notBlank('add-on id', $addonId);

        return $this->call('POST', '/billing/addons/get', ['id' => $addonId], isReadOnly: true)
            ->data(Addon::fromArray(...));
    }

    /**
     * @return string The new add-on id.
     *
     * @throws ApiException
     */
    public function createAddon(AddonInput $addon): string
    {
        return $this->call('POST', '/billing/addons', $addon->toArray())->data(
            static fn (array $data): string => Payload::of($data)->string('id'),
        );
    }

    /**
     * @throws ApiException
     * @throws \InvalidArgumentException When the add-on id is blank.
     */
    public function updateAddon(string $addonId, AddonUpdate $update): void
    {
        Assert::notBlank('add-on id', $addonId);

        $this->call('POST', '/billing/addons/update', ['id' => $addonId] + $update->toArray())->requireOk();
    }

    /**
     * @return Assignment|null Null when the private label holds no package.
     *
     * @throws ApiException
     * @throws \InvalidArgumentException When an argument is blank or unusable in a URL.
     */
    public function getAssignment(string $tenantId): ?Assignment
    {
        return $this->call('GET', self::path('/private-labels/%s/package', $tenantId))->data(
            static fn (array $data): ?Assignment => Payload::of($data)
                ->decodeOrNull('assignment', Assignment::fromArray(...)),
        );
    }

    /**
     * Assigns a package, spending the reseller's allowance at once. Over the allowance, nothing
     * is written and the outcome asks for confirmation: call again with its confirmation key.
     *
     * @throws ApiException
     * @throws \InvalidArgumentException When an argument is blank or unusable in a URL.
     */
    public function assignPackage(
        string $tenantId,
        string $packageId,
        ?string $confirmationKey = null,
    ): AllocationOutcome {
        Assert::notBlank('package id', $packageId);

        return $this->call('POST', self::path('/private-labels/%s/package', $tenantId), self::optional([
            'packageId' => $packageId,
            'confirmationKey' => $confirmationKey,
        ]))->data(AllocationOutcome::fromArray(...));
    }

    /**
     * @throws ApiException
     * @throws \InvalidArgumentException When an argument is blank or unusable in a URL.
     */
    public function unassignPackage(string $tenantId): void
    {
        $this->call('DELETE', self::path('/private-labels/%s/package', $tenantId))->requireOk();
    }

    /**
     * Attaches $quantity units of an add-on. Over the allowance, nothing is written and the
     * outcome asks for confirmation: call again with its confirmation key.
     *
     * @throws ApiException
     * @throws \InvalidArgumentException When an argument is blank or unusable in a URL.
     */
    public function attachAddon(
        string $tenantId,
        string $addonId,
        int $quantity,
        ?string $confirmationKey = null,
    ): AllocationOutcome {
        Assert::notBlank('add-on id', $addonId);

        return $this->call('POST', self::path('/private-labels/%s/package/addons', $tenantId), self::optional([
            'addonId' => $addonId,
            'quantity' => $quantity,
            'confirmationKey' => $confirmationKey,
        ]))->data(AllocationOutcome::fromArray(...));
    }

    /**
     * @throws ApiException
     * @throws \InvalidArgumentException When an argument is blank or unusable in a URL.
     */
    public function removeAddon(string $tenantId, string $subscriptionAddonId): void
    {
        $this->call(
            'DELETE',
            self::path('/private-labels/%s/package/addons/%s', $tenantId, $subscriptionAddonId),
        )->requireOk();
    }

    /**
     * @throws ApiException
     */
    public function getQuota(): Quota
    {
        return $this->call('GET', '/billing/quota')->data(Quota::fromArray(...));
    }

    /**
     * @param string|null $period A calendar month, YYYY-MM (UTC); null for the current month.
     *
     * @throws ApiException
     * @throws \InvalidArgumentException When the period is not written as YYYY-MM.
     */
    public function getUsage(?string $period = null): Usage
    {
        if ($period !== null && preg_match(self::PERIOD_PATTERN, $period) !== 1) {
            throw new \InvalidArgumentException('The usage period must be a calendar month written as YYYY-MM.');
        }
        $query = $period === null ? '' : '?period=' . rawurlencode($period);

        return $this->call('GET', '/usage' . $query)->data(Usage::fromArray(...));
    }

    /**
     * The rate-limit headers of the latest API answer (token calls excluded), for pacing bulk work.
     *
     * @phpstan-impure
     */
    public function lastRateLimit(): ?RateLimitInfo
    {
        return $this->authorized->lastRateLimit();
    }

    /**
     * Every row of a catalogue, read page by page.
     *
     * @template T
     *
     * @param callable(array<mixed>): T $decoder
     *
     * @return list<T>
     *
     * @throws ApiException
     */
    private function search(string $path, string $listKey, callable $decoder): array
    {
        $rows = [];
        for ($page = 1; $page <= self::MAX_SEARCH_PAGES; $page++) {
            $query = ['query' => '', 'page' => $page, 'pageSize' => self::SEARCH_PAGE_SIZE];
            $exchange = $this->call('POST', $path, $query, isReadOnly: true);
            $found = $exchange->data(
                static fn (array $data): array => Payload::of($data)->decodeList($listKey, $decoder),
            );
            $total = $exchange->data(static fn (array $data): int => Payload::of($data)->int('total'));
            array_push($rows, ...$found);
            if ($found === [] || count($rows) >= $total) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param string $path After /console/{slug}/reseller.
     * @param array<string, mixed>|null $payload
     * @param bool $isReadOnly A POST that only reads, such as a catalogue search; retried as a GET is.
     */
    private function call(
        string $method,
        string $path,
        ?array $payload = null,
        ?int $timeoutSeconds = null,
        bool $isReadOnly = false,
    ): Exchange {
        return $this->sender->send($method, $path, $payload, $timeoutSeconds ?? $this->config->timeout, $isReadOnly);
    }

    /**
     * Fills the %s placeholders of $template with URL-encoded path segments.
     */
    private static function path(string $template, string ...$segments): string
    {
        return vsprintf($template, array_map(self::segment(...), $segments));
    }

    private static function segment(string $value): string
    {
        if (trim($value) === '' || $value === '.' || $value === '..') {
            throw new \InvalidArgumentException('An identifier in the request path must not be blank, "." or "..".');
        }

        return rawurlencode($value);
    }

    /**
     * Drops the keys whose value is null: the backend refuses null for an optional field.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private static function optional(array $fields): array
    {
        return array_filter($fields, static fn (mixed $value): bool => $value !== null);
    }
}
