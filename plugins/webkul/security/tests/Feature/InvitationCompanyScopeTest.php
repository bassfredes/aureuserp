<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    $signedUrl = URL::signedRoute('security.invitation.accept', [
        'invitation' => $invitation->id,
        'token'      => $invitation->token,
    ]);

    expect(fn () => Livewire::test(AcceptInvitation::class, ['invitation' => $invitation->id, 'token' => $invitation->token]))
        ->not->toThrow(Throwable::class);

    expect($signedUrl)->toContain((string) $invitation->id);
});

it('aborts with 410 when a guest tries to accept an already-accepted invitation', function () {
    $companyA = Company::factory()->create();
    $invitation = CompanyContext::runForCompany(
        $companyA->id, reason: 'fixture', caller: __FILE__,
        callback: fn () => Invitation::factory()->create(['company_id' => $companyA->id, 'accepted_at' => now(), 'expires_at' => now()->addDay()]),
    );

    Livewire::test(AcceptInvitation::class, ['invitation' => $invitation->id, 'token' => $invitation->token])
        ->assertStatus(410);
});

it('aborts with 410 when a guest tries to accept an expired invitation', function () {
    $companyA = Company::factory()->create();
    $invitation = CompanyContext::runForCompany(
        $companyA->id, reason: 'fixture', caller: __FILE__,
        callback: fn () => Invitation::factory()->create(['company_id' => $companyA->id, 'accepted_at' => null, 'expires_at' => now()->subDay()]),
    );

    Livewire::test(AcceptInvitation::class, ['invitation' => $invitation->id, 'token' => $invitation->token])
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

    Livewire::test(AcceptInvitation::class, ['invitation' => $invitation->id, 'token' => $invitation->token])
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

// ── IDOR: signed URL authorization must survive the Livewire round trip ──
// A signed URL's own signature only ever covered the initial GET
// (routes/web.php's `signed` middleware). The Livewire component then
// carries state across to the mutating `create()` POST via its own,
// separate `/livewire/update` endpoint — unprotected by that middleware.
// These cases prove the fix closes the resulting IDOR: a caller who holds
// one valid signed invitation link cannot swap the component onto a
// different, still-pending invitation and complete an account creation
// under it (Codex adversarial review, #138 PR4).

it('marks the invitation public property #[Locked], rejecting a client-side attempt to retarget it', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $invitationA = CompanyContext::runForCompany(
        $companyA->id, reason: 'fixture', caller: __FILE__,
        callback: fn () => Invitation::factory()->create(['company_id' => $companyA->id, 'accepted_at' => null, 'expires_at' => now()->addDay()]),
    );
    $invitationB = CompanyContext::runForCompany(
        $companyB->id, reason: 'fixture', caller: __FILE__,
        callback: fn () => Invitation::factory()->create(['company_id' => $companyB->id, 'accepted_at' => null, 'expires_at' => now()->addDay()]),
    );

    // Mount legitimately against A's own signed link, then attempt the
    // tamper: swap the bound `invitation` property to point at B's
    // still-pending invitation before calling the mutating action.
    expect(fn () => Livewire::test(AcceptInvitation::class, ['invitation' => $invitationA->id, 'token' => $invitationA->token])
        ->set('invitation', $invitationB->id))
        ->toThrow(Exception::class);

    $invitationA->refresh();
    $invitationB->refresh();

    expect($invitationA->isAccepted())->toBeFalse()
        ->and($invitationB->isAccepted())->toBeFalse();
});

it('rejects the same token/invitation re-validation create() runs on the locked row, independent of #[Locked]', function () {
    // #[Locked] is a client-facing guard enforced by Livewire's own
    // set()/hydrate machinery — there is no supported way to make a
    // *Livewire* request that gets past it. What Codex's review actually
    // asked to prove is that create()'s own guard is a real, working
    // authorization check on its own, not decoration that merely
    // happens to sit behind Locked. So this exercises assertTokenMatches()
    // directly — the exact private method both mount() and create() call,
    // the latter against the row it has just locked for update — the way
    // create() would if it were ever reached with an invitation/token
    // pairing Locked did not originate (e.g. a future code path that
    // calls create() without going through the mounted, locked property).
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $invitationA = CompanyContext::runForCompany(
        $companyA->id, reason: 'fixture', caller: __FILE__,
        callback: fn () => Invitation::factory()->create(['company_id' => $companyA->id, 'accepted_at' => null, 'expires_at' => now()->addDay()]),
    );
    $invitationB = CompanyContext::runForCompany(
        $companyB->id, reason: 'fixture', caller: __FILE__,
        callback: fn () => Invitation::factory()->create(['company_id' => $companyB->id, 'accepted_at' => null, 'expires_at' => now()->addDay()]),
    );

    $component = new AcceptInvitation;

    $reflection = new ReflectionClass($component);
    $tokenProperty = $reflection->getProperty('token');
    $tokenProperty->setAccessible(true);
    $tokenProperty->setValue($component, $invitationA->token);

    $assertTokenMatches = $reflection->getMethod('assertTokenMatches');
    $assertTokenMatches->setAccessible(true);

    // A's own token against A's own invitation must pass silently.
    expect(fn () => $assertTokenMatches->invoke($component, $invitationA))
        ->not->toThrow(Throwable::class);

    // A's token presented against B's invitation — the exact mismatch an
    // attacker holding one valid signed link would produce if they ever
    // got a request past #[Locked] — must be rejected, and specifically
    // with the 403 assertTokenMatches() aborts with, not just any error.
    $rejection = null;

    try {
        $assertTokenMatches->invoke($component, $invitationB);
    } catch (Throwable $caught) {
        $rejection = $caught;
    }

    expect($rejection)->not->toBeNull()
        ->and($rejection)->toBeInstanceOf(HttpException::class)
        ->and($rejection->getStatusCode())->toBe(403);

    $invitationA->refresh();
    $invitationB->refresh();

    expect($invitationA->isAccepted())->toBeFalse()
        ->and($invitationB->isAccepted())->toBeFalse()
        ->and(User::where('email', $invitationB->email)->exists())->toBeFalse()
        ->and(User::where('email', $invitationA->email)->exists())->toBeFalse();
});

it('aborts with 403 when the accept route is hit without a token at all', function () {
    $companyA = Company::factory()->create();

    $invitation = CompanyContext::runForCompany(
        $companyA->id, reason: 'fixture', caller: __FILE__,
        callback: fn () => Invitation::factory()->create(['company_id' => $companyA->id, 'accepted_at' => null, 'expires_at' => now()->addDay()]),
    );

    Livewire::test(AcceptInvitation::class, ['invitation' => $invitation->id])
        ->assertStatus(403);

    $invitation->refresh();

    expect($invitation->isAccepted())->toBeFalse();
});

it('aborts with 403 when the token does not match the invitation it is presented against', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $invitationA = CompanyContext::runForCompany(
        $companyA->id, reason: 'fixture', caller: __FILE__,
        callback: fn () => Invitation::factory()->create(['company_id' => $companyA->id, 'accepted_at' => null, 'expires_at' => now()->addDay()]),
    );
    $invitationB = CompanyContext::runForCompany(
        $companyB->id, reason: 'fixture', caller: __FILE__,
        callback: fn () => Invitation::factory()->create(['company_id' => $companyB->id, 'accepted_at' => null, 'expires_at' => now()->addDay()]),
    );

    Livewire::test(AcceptInvitation::class, ['invitation' => $invitationB->id, 'token' => $invitationA->token])
        ->assertStatus(403);

    $invitationB->refresh();

    expect($invitationB->isAccepted())->toBeFalse();
});
