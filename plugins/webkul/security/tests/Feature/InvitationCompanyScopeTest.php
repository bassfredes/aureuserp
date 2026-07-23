<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Webkul\Security\Livewire\AcceptInvitation;
use Webkul\Security\Models\Invitation;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('projects');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// ── company_id obligatorio, autorización en create ──────────────────────

it('fails closed when creating an Invitation with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();

    expect(fn () => Invitation::factory()->create(['company_id' => $company->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids a user in company A from creating an Invitation directly under company B by knowing its id', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Invitation::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);
});

it('derives an Invitation.company_id from the acting user\'s default_company_id when omitted', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $invitation = Invitation::factory()->create(['company_id' => null]);

    expect($invitation->company_id)->toBe($companyA->id);
});

it('forbids changing an Invitation\'s company_id, even for a user authorized in both companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $invitation = Invitation::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $invitation->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);
});

it('defaults token, invited_by, and a 7-day expires_at on creation', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $invitation = Invitation::factory()->create(['company_id' => $companyA->id, 'token' => null, 'invited_by' => null, 'expires_at' => null]);

    expect($invitation->token)->not->toBeNull()
        ->and($invitation->invited_by)->toBe($user->id)
        ->and($invitation->expires_at->diffInDays(now()))->toBeLessThanOrEqual(7);
});

// ── isExpired() / isAccepted() ────────────────────────────────────────────

it('reports isExpired() and isAccepted() from persisted state', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $fresh = Invitation::factory()->create(['company_id' => $companyA->id, 'expires_at' => now()->addDay(), 'accepted_at' => null]);
    $expired = Invitation::factory()->create(['company_id' => $companyA->id, 'expires_at' => now()->subDay(), 'accepted_at' => null]);
    $accepted = Invitation::factory()->create(['company_id' => $companyA->id, 'expires_at' => now()->addDay(), 'accepted_at' => now()]);

    expect($fresh->isExpired())->toBeFalse()->and($fresh->isAccepted())->toBeFalse()
        ->and($expired->isExpired())->toBeTrue()
        ->and($accepted->isAccepted())->toBeTrue();
});

// ── guest accept flow: no HasCompanyScope lockout, expiry/consumed enforced ──

it('lets an unauthenticated guest resolve a fresh invitation via the signed accept route', function () {
    $companyA = Company::factory()->create();
    $invitation = CompanyContext::runForCompany(
        $companyA->id, reason: 'fixture', caller: __FILE__,
        callback: fn () => Invitation::factory()->create(['company_id' => $companyA->id, 'accepted_at' => null, 'expires_at' => now()->addDay()]),
    );

    $signedUrl = URL::signedRoute('security.invitation.accept', ['invitation' => $invitation->id]);

    expect(fn () => Livewire::test(AcceptInvitation::class, ['invitation' => $invitation->id]))
        ->not->toThrow(Throwable::class);

    expect($signedUrl)->toContain((string) $invitation->id);
});

it('aborts with 410 when a guest tries to accept an already-accepted invitation', function () {
    $companyA = Company::factory()->create();
    $invitation = CompanyContext::runForCompany(
        $companyA->id, reason: 'fixture', caller: __FILE__,
        callback: fn () => Invitation::factory()->create(['company_id' => $companyA->id, 'accepted_at' => now(), 'expires_at' => now()->addDay()]),
    );

    Livewire::test(AcceptInvitation::class, ['invitation' => $invitation->id])
        ->assertStatus(410);
});

it('aborts with 410 when a guest tries to accept an expired invitation', function () {
    $companyA = Company::factory()->create();
    $invitation = CompanyContext::runForCompany(
        $companyA->id, reason: 'fixture', caller: __FILE__,
        callback: fn () => Invitation::factory()->create(['company_id' => $companyA->id, 'accepted_at' => null, 'expires_at' => now()->subDay()]),
    );

    Livewire::test(AcceptInvitation::class, ['invitation' => $invitation->id])
        ->assertStatus(410);
});

it('accepting an Invitation creates a User carrying the captured company and role, and marks it accepted', function () {
    $companyA = Company::factory()->create();
    $role = Role::findOrCreate('invited-role-'.uniqid(), 'web');

    $invitation = CompanyContext::runForCompany(
        $companyA->id, reason: 'fixture', caller: __FILE__,
        callback: fn () => Invitation::factory()->create([
            'company_id'  => $companyA->id,
            'role_id'     => $role->id,
            'accepted_at' => null,
            'expires_at'  => now()->addDay(),
        ]),
    );

    Livewire::test(AcceptInvitation::class, ['invitation' => $invitation->id])
        ->set('data.name', 'New Invitee')
        ->set('data.password', 'Password123!')
        ->set('data.passwordConfirmation', 'Password123!')
        ->call('create')
        ->assertHasNoErrors();

    $invitation->refresh();

    expect($invitation->isAccepted())->toBeTrue();

    $newUser = User::where('email', $invitation->email)->first();

    expect($newUser)->not->toBeNull()
        ->and($newUser->default_company_id)->toBe($companyA->id)
        ->and($newUser->allowedCompanies()->pluck('companies.id'))->toContain($companyA->id)
        ->and($newUser->hasRole($role->name))->toBeTrue();
});
