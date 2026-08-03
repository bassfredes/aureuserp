<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Chatter\Models\Attachment;
use Webkul\Chatter\Models\Follower;
use Webkul\Chatter\Models\Message;
use Webkul\Employee\Models\Employee;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('employees');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

function chatterEmployeeIn(int $companyId): Employee
{
    return CompanyContext::runForAllCompanies(
        reason: 'fixture',
        caller: __FILE__,
        callback: fn () => Employee::factory()->create(['company_id' => $companyId]),
    );
}

function chatterCompany(): Company
{
    return CompanyContext::runForAllCompanies(
        reason: 'fixture',
        caller: __FILE__,
        callback: fn () => Company::factory()->create(),
    );
}

function chatterPartner(array $attributes = []): Partner
{
    return CompanyContext::runForAllCompanies(
        reason: 'fixture',
        caller: __FILE__,
        callback: fn () => Partner::factory()->create($attributes),
    );
}

// ── resolveChatterCompanyId(): the three owner categories ───────────────

it('derives Message.company_id from the owner itself when the owner IS a Company', function () {
    $company = chatterCompany();

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id])));

    $message = $company->addMessage(['body' => 'hello']);

    expect($message->company_id)->toBe($company->id);
});

it('derives Message.company_id from a HasCompanyScope owner (Employee), never from the acting user\'s own company', function () {
    $companyOwner = chatterCompany();
    $companyActor = chatterCompany();
    $employee = chatterEmployeeIn($companyOwner->id);

    // Acting user's default company differs from the Employee's own
    // company — before this fix, addMessage() derived company_id from the
    // ACTOR, which would have mislabeled this message as $companyActor.
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyActor->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyOwner->id]);
    test()->actingAs($user);

    $message = $employee->addMessage(['body' => 'hello']);

    expect($message->company_id)->toBe($companyOwner->id)
        ->and($message->company_id)->not->toBe($companyActor->id);
});

it('derives Message.company_id from an active company-mode CompanyContext when the owner has no HasCompanyScope of its own (Partner)', function () {
    $company = chatterCompany();
    $partner = chatterPartner();

    $message = CompanyContext::runForCompany(
        $company->id,
        reason: 'test',
        caller: __FILE__,
        callback: fn () => $partner->addMessage(['body' => 'hello']),
    );

    expect($message->company_id)->toBe($company->id);
});

it('derives Message.company_id from the authenticated user\'s default company when the owner has no HasCompanyScope of its own (Partner)', function () {
    $company = chatterCompany();
    $partner = chatterPartner();

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id])));

    $message = $partner->addMessage(['body' => 'hello']);

    expect($message->company_id)->toBe($company->id);
});

it('fails closed deriving Message.company_id for a Partner owner with no CompanyContext and no authenticated user', function () {
    $partner = chatterPartner();

    expect(fn () => $partner->addMessage(['body' => 'hello']))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('chatter_messages', ['body' => 'hello']);
});

// ── explicit company_id: cross-check only, never trusted ────────────────

it('rejects an explicit Message company_id that does not match the one derived from messageable', function () {
    $companyOwner = chatterCompany();
    $companyOther = chatterCompany();
    $employee = chatterEmployeeIn($companyOwner->id);

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyOwner->id])));

    expect(fn () => Message::create([
        'messageable_type' => $employee->getMorphClass(),
        'messageable_id'   => $employee->id,
        'type'             => 'comment',
        'body'             => 'spoofed',
        'company_id'       => $companyOther->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('chatter_messages', ['body' => 'spoofed']);
});

it('accepts an explicit Message company_id that matches the one derived from messageable', function () {
    $company = chatterCompany();
    $employee = chatterEmployeeIn($company->id);

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id])));

    $message = Message::create([
        'messageable_type' => $employee->getMorphClass(),
        'messageable_id'   => $employee->id,
        'type'             => 'comment',
        'body'             => 'matches',
        'company_id'       => $company->id,
    ]);

    expect($message->exists)->toBeTrue()
        ->and($message->company_id)->toBe($company->id);
});

// ── owner identity is immutable once persisted ───────────────────────────

it('forbids retargeting an existing Message to a different messageable owner', function () {
    $company = chatterCompany();
    $employeeA = chatterEmployeeIn($company->id);
    $employeeB = chatterEmployeeIn($company->id);

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id])));

    $message = $employeeA->addMessage(['body' => 'hello']);

    expect(fn () => $message->update([
        'messageable_type' => $employeeB->getMorphClass(),
        'messageable_id'   => $employeeB->id,
    ]))->toThrow(AuthorizationException::class);
});

it('requires a resolvable messageable owner to save a Message at all', function () {
    expect(fn () => Message::create([
        'messageable_type' => null,
        'messageable_id'   => null,
        'type'             => 'comment',
        'body'             => 'orphan',
    ]))->toThrow(AuthorizationException::class);
});

// ── read isolation: strict_company/company_or_shared derived company ────

it('shows a Message belonging to the user\'s own company, not company B\'s', function () {
    $companyA = chatterCompany();
    $companyB = chatterCompany();
    $employeeA = chatterEmployeeIn($companyA->id);
    $employeeB = chatterEmployeeIn($companyB->id);

    $userA = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($userA);
    $messageA = $employeeA->addMessage(['body' => 'in A']);

    $userB = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));
    test()->actingAs($userB);
    $messageB = $employeeB->addMessage(['body' => 'in B']);

    test()->actingAs($userA);

    $ids = Message::query()->pluck('id');

    expect($ids)->toContain($messageA->id)
        ->not->toContain($messageB->id);
});

// ── Attachment: same derivation + message_id consistency ────────────────

it('derives Attachment.company_id from messageable', function () {
    $company = chatterCompany();
    $employee = chatterEmployeeIn($company->id);

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id])));

    $attachment = Attachment::create([
        'messageable_type'    => $employee->getMorphClass(),
        'messageable_id'      => $employee->id,
        'name'                => 'file.pdf',
        'original_file_name'  => 'file.pdf',
        'file_path'           => 'attachments/file.pdf',
        'file_size'           => 10,
        'mime_type'           => 'application/pdf',
    ]);

    expect($attachment->company_id)->toBe($company->id);
});

it('rejects an Attachment whose message_id points to a Message of a different messageable owner', function () {
    $company = chatterCompany();
    $employeeA = chatterEmployeeIn($company->id);
    $employeeB = chatterEmployeeIn($company->id);

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id])));

    $messageOnB = $employeeB->addMessage(['body' => 'on B']);

    expect(fn () => Attachment::create([
        'messageable_type'   => $employeeA->getMorphClass(),
        'messageable_id'     => $employeeA->id,
        'message_id'         => $messageOnB->id,
        'name'               => 'file.pdf',
        'original_file_name' => 'file.pdf',
        'file_path'          => 'attachments/file.pdf',
        'file_size'          => 10,
        'mime_type'          => 'application/pdf',
    ]))->toThrow(AuthorizationException::class);
});

it('rejects an Attachment whose message_id points to a Message of a different company', function () {
    $companyA = chatterCompany();
    $companyB = chatterCompany();
    $employeeA = chatterEmployeeIn($companyA->id);
    $employeeB = chatterEmployeeIn($companyB->id);

    $userB = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));
    test()->actingAs($userB);
    $messageOnB = $employeeB->addMessage(['body' => 'on B']);

    $userA = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($userA);

    // Same owner identity (employeeA) but message_id references a Message
    // whose company (B) differs — this can only happen if the two
    // messageable ids also differ (the owner determines the company), so
    // this exercises the identity guard again from the Attachment side
    // rather than a genuinely independent company mismatch; kept as its
    // own test because it documents the guard fires from message_id, not
    // only from a spoofed company_id.
    expect(fn () => Attachment::create([
        'messageable_type'   => $employeeA->getMorphClass(),
        'messageable_id'     => $employeeA->id,
        'message_id'         => $messageOnB->id,
        'name'               => 'file.pdf',
        'original_file_name' => 'file.pdf',
        'file_path'          => 'attachments/file.pdf',
        'file_size'          => 10,
        'mime_type'          => 'application/pdf',
    ]))->toThrow(AuthorizationException::class);
});

it('accepts an Attachment whose message_id points to a Message of the same owner and company', function () {
    $company = chatterCompany();
    $employee = chatterEmployeeIn($company->id);

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id])));

    $message = $employee->addMessage(['body' => 'hello']);

    $attachment = Attachment::create([
        'messageable_type'   => $employee->getMorphClass(),
        'messageable_id'     => $employee->id,
        'message_id'         => $message->id,
        'name'               => 'file.pdf',
        'original_file_name' => 'file.pdf',
        'file_path'          => 'attachments/file.pdf',
        'file_size'          => 10,
        'mime_type'          => 'application/pdf',
    ]);

    expect($attachment->exists)->toBeTrue()
        ->and($attachment->company_id)->toBe($company->id);
});

// ── Follower: same derivation, via addFollower() and the model directly ─

it('derives Follower.company_id from the followable owner via addFollower()', function () {
    $company = chatterCompany();
    $employee = chatterEmployeeIn($company->id);
    $partner = chatterPartner();

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id])));

    $follower = $employee->addFollower($partner);

    expect($follower->company_id)->toBe($company->id);
});

it('fails closed adding a Follower to a Partner (followable) with no CompanyContext and no authenticated user', function () {
    $followable = chatterPartner();
    $follower = chatterPartner();

    expect(fn () => $followable->addFollower($follower))
        ->toThrow(AuthorizationException::class);
});

it('forbids retargeting an existing Follower to a different followable owner', function () {
    $company = chatterCompany();
    $employeeA = chatterEmployeeIn($company->id);
    $employeeB = chatterEmployeeIn($company->id);
    $partner = chatterPartner();

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id])));

    $follower = $employeeA->addFollower($partner);

    expect(fn () => $follower->update([
        'followable_type' => $employeeB->getMorphClass(),
        'followable_id'   => $employeeB->id,
    ]))->toThrow(AuthorizationException::class);
});

it('shows a Follower belonging to the user\'s own company, not company B\'s', function () {
    $companyA = chatterCompany();
    $companyB = chatterCompany();
    $employeeA = chatterEmployeeIn($companyA->id);
    $employeeB = chatterEmployeeIn($companyB->id);
    $partner = chatterPartner();

    $userA = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($userA);
    $followerA = $employeeA->addFollower($partner);

    $userB = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));
    test()->actingAs($userB);
    $followerB = $employeeB->addFollower($partner);

    test()->actingAs($userA);

    $ids = Follower::query()->pluck('id');

    expect($ids)->toContain($followerA->id)
        ->not->toContain($followerB->id);
});
