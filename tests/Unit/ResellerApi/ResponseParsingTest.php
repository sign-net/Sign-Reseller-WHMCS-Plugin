<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi;

use PHPUnit\Framework\Attributes\Test;
use SignNet\ResellerApi\Exception\ForbiddenException;
use SignNet\ResellerApi\Exception\ServerException;
use SignNet\ResellerApi\Http\Response;
use SignNet\ResellerApi\Model\AllocationOutcome;
use SignNet\ResellerApi\Model\ItemCode;
use SignNet\ResellerApi\Model\PortalUser;
use SignNet\ResellerApi\Model\ProvisionRequest;
use SignNet\ResellerApi\Model\ProvisionResult;
use SignNet\ResellerApi\Model\TenantStatus;

final class ResponseParsingTest extends ClientTestCase
{
    private const T = ApiFixtures::TENANT_ID;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storeUsableToken();
    }

    #[Test]
    public function itReadsAProvisionThatAssignedThePackage(): void
    {
        $this->transport->queueData(ApiFixtures::provisionResult([
            'outcome' => 'Assigned',
            'assignmentId' => 'asg-1',
            'allocated' => ['documents' => 500, 'seats' => 10, 'templates' => 25],
        ]));

        $result = $this->provision();

        self::assertSame(self::T, $result->tenantId);
        self::assertSame('u-owner', $result->ownerUserId);
        self::assertTrue($result->domainSetup->attached);
        self::assertFalse($result->domainSetup->verified);
        self::assertSame('CNAME', $result->domainSetup->records[0]->type);
        self::assertSame('sign.acme.test', $result->domainSetup->records[0]->name);
        self::assertSame('cname.vercel-dns.com', $result->domainSetup->records[0]->value);
        self::assertSame('Point DNS at the hosting platform.', $result->domainSetup->note);
        self::assertNotNull($result->package);
        self::assertSame(AllocationOutcome::ASSIGNED, $result->package->outcome);
        self::assertTrue($result->package->isDone());
        self::assertSame('asg-1', $result->package->assignmentId);
        self::assertSame(['documents' => 500, 'seats' => 10, 'templates' => 25], $result->package->allocated);
    }

    #[Test]
    public function itReadsAProvisionWhosePackageNeedsConfirmation(): void
    {
        $this->transport->queueData(ApiFixtures::provisionResult([
            'outcome' => 'ConfirmationRequired',
            'confirmation' => ['key' => 'confirm-key', 'expiresAt' => 1_758_800_000_000],
            'warnings' => [['itemCode' => 'seats', 'requested' => 10, 'remaining' => 3, 'excess' => 7]],
        ]));

        $package = $this->provision()->package;

        self::assertNotNull($package);
        self::assertTrue($package->isConfirmationRequired());
        self::assertFalse($package->isDone());
        self::assertSame('confirm-key', $package->confirmationKey);
        self::assertSame(1_758_800_000_000, $package->confirmationExpiresAt);
        self::assertCount(1, $package->warnings);
        self::assertSame(ItemCode::SEATS, $package->warnings[0]->itemCode);
        self::assertSame(10, $package->warnings[0]->requested);
        self::assertSame(3, $package->warnings[0]->remaining);
        self::assertSame(7, $package->warnings[0]->excess);
    }

    #[Test]
    public function itReadsAProvisionWhosePackageFailed(): void
    {
        $this->transport->queueData(
            ApiFixtures::provisionResult(['outcome' => 'Failed', 'error' => 'NO_SUBSCRIPTION']),
        );

        $package = $this->provision()->package;

        self::assertNotNull($package);
        self::assertSame(AllocationOutcome::FAILED, $package->outcome);
        self::assertSame('NO_SUBSCRIPTION', $package->error);
        self::assertSame([], $package->warnings);
    }

    #[Test]
    public function itReadsAProvisionWithoutAPackageAndADomainNotYetAttached(): void
    {
        $body = ApiFixtures::provisionResult();
        $body['domainSetup'] = ['attached' => false, 'verified' => false, 'records' => []];
        $this->transport->queueData($body);

        $result = $this->provision();

        self::assertNull($result->package);
        self::assertFalse($result->domainSetup->attached);
        self::assertSame([], $result->domainSetup->records);
        self::assertNull($result->domainSetup->note);
    }

    #[Test]
    public function itReadsEveryFieldOfAPrivateLabel(): void
    {
        $this->transport->queueData(ApiFixtures::privateLabel());

        $label = $this->client->getPrivateLabel(self::T);

        self::assertSame(self::T, $label->tenantId);
        self::assertSame('sign.acme.test', $label->primaryHost);
        self::assertNull($label->customDomain);
        self::assertSame(TenantStatus::SUSPENDED, $label->status);
        self::assertTrue($label->isSuspended());
        self::assertSame(ApiFixtures::CREATED_AT, $label->createdAt);
        self::assertSame(ApiFixtures::CREATED_AT + 1000, $label->suspendedAt);
        self::assertSame(TenantStatus::SUSPENDED_BY_RESELLER, $label->suspendedBy);
        self::assertSame('Unpaid invoice', $label->suspendReason);
        $owner = $label->owner;
        self::assertNotNull($owner);
        self::assertSame('owner@acme.test', $owner->email);
        self::assertSame('u-owner', $owner->userId);
        self::assertSame('Ann', $owner->firstName);
        self::assertSame('Lee', $owner->lastName);
        self::assertSame(3, $label->memberCount);
        $package = $label->package;
        self::assertNotNull($package);
        self::assertSame('STARTER', $package->code);
        self::assertSame(
            ['documents' => 500, 'seats' => 20, 'templates' => 25, 'notarizations' => 0],
            $package->allocated,
        );
        self::assertSame('sa-1', $package->addons[0]->subscriptionAddonId);
        self::assertSame(2, $package->addons[0]->quantity);
        self::assertTrue($label->domainSetup->attached);
    }

    #[Test]
    public function itReadsAnActivePrivateLabelWithoutOwnerOrPackage(): void
    {
        $body = ApiFixtures::privateLabel();
        $body['status'] = 'Active';
        $body['suspendedAt'] = null;
        $body['suspendedBy'] = null;
        $body['suspendReason'] = null;
        $body['owner'] = null;
        $body['assignment'] = null;
        $body['customDomain'] = 'portal.acme.test';
        $this->transport->queueData($body);

        $label = $this->client->getPrivateLabel(self::T);

        self::assertFalse($label->isSuspended());
        self::assertNull($label->owner);
        self::assertNull($label->package);
        self::assertNull($label->suspendedAt);
        self::assertSame('portal.acme.test', $label->customDomain);
    }

    #[Test]
    public function itListsPrivateLabels(): void
    {
        $this->transport->queueData(['privateLabels' => [
            ['tenantId' => 't-1', 'primaryHost' => 'one.acme.test', 'customDomain' => null, 'status' => 'Active'],
            ['tenantId' => 't-2', 'primaryHost' => 'two.acme.test', 'customDomain' => null, 'status' => 'Suspended'],
        ], 'provisionedCount' => 2, 'period' => '2026-07']);

        [$active, $suspended] = $this->client->listPrivateLabels();

        self::assertSame('t-1', $active->tenantId);
        self::assertSame('one.acme.test', $active->primaryHost);
        self::assertFalse($active->isSuspended());
        self::assertTrue($suspended->isSuspended());
    }

    #[Test]
    public function itReadsSuspensionStates(): void
    {
        $this->transport->queueData(ApiFixtures::suspensionState());
        $this->transport->queueData([
            'tenantId' => self::T,
            'status' => 'Active',
            'suspendedAt' => null,
            'suspendedBy' => null,
            'suspendReason' => null,
        ]);

        $suspended = $this->client->suspendPrivateLabel(self::T);
        $active = $this->client->unsuspendPrivateLabel(self::T);

        self::assertTrue($suspended->isSuspended());
        self::assertTrue($suspended->isSuspendedByPlatform());
        self::assertSame(ApiFixtures::CREATED_AT, $suspended->suspendedAt);
        self::assertFalse($active->isSuspended());
        self::assertNull($active->suspendedBy);
    }

    #[Test]
    public function itReadsTheDomainSetupAfterAnAttach(): void
    {
        $this->transport->queueData(['domainSetup' => ApiFixtures::domainSetup()]);

        $setup = $this->client->attachDomain(self::T);

        self::assertTrue($setup->attached);
        self::assertCount(1, $setup->records);
    }

    #[Test]
    public function itReadsPortalUsers(): void
    {
        $owner = ['role' => 'owner', 'id' => 'u-owner', 'isOwner' => true] + ApiFixtures::user();
        $this->transport->queueData(['members' => [$owner, ApiFixtures::user()]]);
        $this->transport->queueData(['status' => 'added', 'userID' => 'u-1', 'created' => false]);

        [$first, $second] = $this->client->listUsers(self::T);
        $added = $this->client->addUser(self::T, 'member@acme.test', 'Bo', 'Chan');

        self::assertTrue($first->isOwner());
        self::assertSame(PortalUser::ROLE_MEMBER, $second->role);
        self::assertSame('member@acme.test', $second->email);
        self::assertSame('Bo', $second->firstName);
        self::assertSame('Chan', $second->lastName);
        self::assertSame('u-1', $second->userId);
        self::assertSame('Active', $second->status);
        self::assertSame(ApiFixtures::CREATED_AT, $second->createdAt);
        self::assertSame('u-1', $added->userId);
        self::assertFalse($added->created);
    }

    #[Test]
    public function itReadsPackages(): void
    {
        $this->transport->queueData(ApiFixtures::page('packages', [ApiFixtures::packageSummary()]));
        $this->transport->queueData(ApiFixtures::package());

        [$summary] = $this->client->listPackages();
        $package = $this->client->getPackage('pkg-1');

        self::assertSame('pkg-1', $summary->packageId);
        self::assertNull($summary->description);
        self::assertSame(1900, $summary->basePriceMinor);
        self::assertTrue($summary->isActive);
        self::assertSame('For small teams', $package->description);
        self::assertSame('Monthly', $package->billingCycle);
        self::assertSame(ApiFixtures::CREATED_AT + 5, $package->updatedAt);
        self::assertSame(4, $package->assignedCount);
        self::assertSame(ItemCode::SEATS, $package->items[0]->itemCode);
        self::assertSame(10, $package->items[0]->includedQty);
        self::assertSame(5, $package->items[0]->overageBundleSize);
        self::assertSame(900, $package->items[0]->overageBundlePriceMinor);
    }

    #[Test]
    public function itReadsAddons(): void
    {
        $this->transport->queueData(ApiFixtures::page('addons', [ApiFixtures::addonSummary()]));
        $this->transport->queueData(ApiFixtures::addon());

        [$summary] = $this->client->listAddons();
        $addon = $this->client->getAddon('add-1');

        self::assertSame('add-1', $summary->addonId);
        self::assertSame(500, $summary->priceMinor);
        self::assertSame('Five more seats', $addon->description);
        self::assertTrue($addon->grantsLocked);
        self::assertSame(2, $addon->activeAttachments);
        self::assertSame(5, $addon->items[0]->grantedQty);
    }

    #[Test]
    public function itReadsAnAbsentAndAPresentAssignment(): void
    {
        $this->transport->queueData(['assignment' => null]);
        $this->transport->queueData(['assignment' => ApiFixtures::assignment()]);

        $absent = $this->client->getAssignment(self::T);
        $assignment = $this->client->getAssignment(self::T);

        self::assertNull($absent);
        self::assertNotNull($assignment);
        self::assertSame('asg-1', $assignment->assignmentId);
        self::assertSame('pkg-1', $assignment->packageId);
        self::assertSame('STARTER', $assignment->code);
        self::assertSame('Starter', $assignment->name);
        self::assertSame('USD', $assignment->currency);
        self::assertSame(ApiFixtures::CREATED_AT, $assignment->assignedAt);
        self::assertSame('add-1', $assignment->addons[0]->addonId);
        self::assertSame('SEATS5', $assignment->addons[0]->code);
        self::assertSame('5 seats', $assignment->addons[0]->name);
    }

    #[Test]
    public function itReadsAllocationOutcomes(): void
    {
        $this->transport->queueData([
            'outcome' => 'ConfirmationRequired',
            'confirmation' => ['key' => 'k-1', 'expiresAt' => 1_758_800_000_000],
            'warnings' => [],
        ]);
        $this->transport->queueData(['outcome' => 'Attached', 'subscriptionAddonId' => 'sa-9']);

        $assign = $this->client->assignPackage(self::T, 'pkg-1');
        $attach = $this->client->attachAddon(self::T, 'add-1', 1);

        self::assertTrue($assign->isConfirmationRequired());
        self::assertSame('k-1', $assign->confirmationKey);
        self::assertSame(AllocationOutcome::ATTACHED, $attach->outcome);
        self::assertTrue($attach->isDone());
        self::assertSame('sa-9', $attach->subscriptionAddonId);
    }

    #[Test]
    public function itReadsAQuotaWithAPlan(): void
    {
        $this->transport->queueData(ApiFixtures::quotaWithPlan());

        $quota = $this->client->getQuota();

        self::assertTrue($quota->hasPlan());
        $plan = $quota->plan;
        self::assertNotNull($plan);
        self::assertSame('Reseller Pro', $plan->packageName);
        self::assertSame('Monthly', $plan->billingCycle);
        self::assertSame(ApiFixtures::CREATED_AT, $quota->windowFrom);
        self::assertSame(ApiFixtures::CREATED_AT + 2_592_000_000, $quota->windowTo);
        $seats = $quota->item(ItemCode::SEATS);
        self::assertNotNull($seats);
        self::assertSame(50, $seats->includedQty);
        self::assertSame(30, $seats->allocatedNow);
        self::assertSame(12, $seats->used);
        self::assertSame(2, $seats->ownUsed);
        self::assertSame(0, $seats->labelsBeyondAllocation);
        self::assertSame(2, $seats->usedOutsideAllocations());
        self::assertSame(18, $seats->remaining);
        self::assertSame(0, $seats->overAllocatedBy);
        self::assertNull($quota->item(ItemCode::DOCUMENTS));
        self::assertSame(640, $quota->privateLabels[0]->billed['documents']);
        self::assertSame(500, $quota->privateLabels[0]->allocatedInPeriod['documents']);
        self::assertSame(4, $quota->privateLabels[0]->actual['seats']);
    }

    #[Test]
    public function itReadsAQuotaWithoutAPlan(): void
    {
        $this->transport->queueData(ApiFixtures::quotaWithoutPlan());

        $quota = $this->client->getQuota();

        self::assertFalse($quota->hasPlan());
        self::assertNull($quota->windowFrom);
        self::assertNull($quota->windowTo);
        self::assertSame([], $quota->items);
        self::assertSame([], $quota->privateLabels);
    }

    #[Test]
    public function itReadsEveryQuotaItemIncludingOnesTheFormsDoNotOffer(): void
    {
        $body = ApiFixtures::quotaWithPlan();
        $body['items'][] = [
            'itemCode' => 'notarizations',
            'includedQty' => 100,
            'allocatedNow' => 0,
            'used' => 3,
            'remaining' => 100,
            'overAllocatedBy' => 0,
        ];
        $this->transport->queueData($body);

        $quota = $this->client->getQuota();

        $notarisations = $quota->item('notarizations');
        self::assertNotNull($notarisations);
        self::assertSame(100, $notarisations->remaining);
        self::assertSame(0, $notarisations->usedOutsideAllocations(), 'An older backend reports no outside use.');
    }

    #[Test]
    public function itReadsUsage(): void
    {
        $this->transport->queueData(ApiFixtures::usage());

        $usage = $this->client->getUsage('2026-07');

        self::assertSame('2026-07', $usage->period);
        self::assertSame(
            ['documents' => 12, 'notarizations' => 0, 'points' => 3400, 'seats' => 7, 'templates' => 3],
            $usage->total,
        );
        self::assertSame(self::T, $usage->privateLabels[0]->tenantId);
        self::assertSame(3400, $usage->privateLabels[0]->metrics['points']);
    }

    #[Test]
    public function itIgnoresFieldsItDoesNotKnow(): void
    {
        $body = ApiFixtures::privateLabel() + ['sso_url' => 'https://example.test', 'labels' => ['x']];
        $this->transport->queueData($body);

        self::assertSame(self::T, $this->client->getPrivateLabel(self::T)->tenantId);
    }

    #[Test]
    public function itNamesTheMissingFieldWhenARequiredOneIsAbsent(): void
    {
        $body = ApiFixtures::privateLabel();
        unset($body['domainSetup']['records'][0]['type']);
        $this->transport->queueData($body);

        try {
            $this->client->getPrivateLabel(self::T);
            self::fail('A response without a required field was accepted.');
        } catch (ServerException $exception) {
            self::assertStringContainsString('"data.domainSetup.records.0.type"', $exception->getMessage());
            self::assertSame(200, $exception->httpStatus);
            self::assertSame($this->transport->lastRequest()->header('X-Request-Id'), $exception->requestId);
        }
    }

    #[Test]
    public function itRefusesAMistypedField(): void
    {
        $body = ApiFixtures::quotaWithPlan();
        $body['window']['from'] = '2026-07-01';
        $this->transport->queueData($body);

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('"data.window.from" must be an integer');

        $this->client->getQuota();
    }

    #[Test]
    public function itRefusesAnUnknownAllocationOutcome(): void
    {
        $this->transport->queueData(['outcome' => 'queued']);

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('"data.outcome"');

        $this->client->assignPackage(self::T, 'pkg-1');
    }

    #[Test]
    public function itRefusesASuccessfulResponseThatIsNotJson(): void
    {
        $this->transport->queue(new Response(200, ['Content-Type' => 'text/html'], '<html>maintenance</html>'));

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('not valid JSON');

        $this->client->getQuota();
    }

    #[Test]
    public function itRefusesASuccessThatIsNotAnOkEnvelope(): void
    {
        $this->transport->queueJson(200, ApiFixtures::quotaWithoutPlan());

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('"status" must be "OK"');

        $this->client->getQuota();
    }

    #[Test]
    public function itRefusesACallWithNothingToReadWhenItsAnswerIsNotOk(): void
    {
        $this->transport->queueJson(200, ['status' => 'Err', 'error' => ['code' => 'NOPE', 'message' => 'No.']]);

        $this->expectException(ServerException::class);

        $this->client->deprovisionPrivateLabel(self::T);
    }

    #[Test]
    public function itReportsTheScopesOfTheCurrentToken(): void
    {
        self::assertSame(self::ALL_SCOPES, $this->client->grantedScopes());
        self::assertSame([], $this->transport->requests());
    }

    #[Test]
    public function itKeepsTheRateLimitOfTheLatestAnswer(): void
    {
        self::assertNull($this->client->lastRateLimit());
        $this->transport->queueData(
            ApiFixtures::quotaWithoutPlan(),
            ['RateLimit-Limit' => '60', 'RateLimit-Remaining' => '59', 'RateLimit-Reset' => '60'],
        );
        $this->transport->queueError(403, 'insufficient_scope', ['RateLimit-Remaining' => '58']);

        $this->client->getQuota();
        $afterSuccess = $this->client->lastRateLimit();
        try {
            $this->client->getQuota();
            self::fail('Expected a ForbiddenException.');
        } catch (ForbiddenException) {
            $afterFailure = $this->client->lastRateLimit();
        }

        self::assertNotNull($afterSuccess);
        self::assertSame(60, $afterSuccess->limit);
        self::assertSame(59, $afterSuccess->remaining);
        self::assertSame(58, $afterFailure?->remaining);
    }

    private function provision(): ProvisionResult
    {
        return $this->client->provisionPrivateLabel(
            new ProvisionRequest('owner@acme.test', 'Ann', 'Lee', 'sign.acme.test', 'Acme', [], 'pkg-1'),
        );
    }
}
