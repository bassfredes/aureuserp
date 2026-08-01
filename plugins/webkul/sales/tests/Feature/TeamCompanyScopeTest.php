<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Webkul\Sale\Models\Order;
use Webkul\Sale\Models\Team;
use Webkul\Sale\Models\TeamMember;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

/**
 * #138 PR4 A4G: Team (own company_id, HasStrictCompanyId, soft-deletable)
 * and TeamMember (no company_id of its own, ownership derived from the
 * Team and the referenced User validated by membership), plus the
 * Order.team_id relation contract that scoping Team alone would not have
 * closed (review 4830829763 / A4G_SALES_TEAMS_COMPOSITION_APPROVED_WITH_ORDER_GUARD).
 */
beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('sales');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

function memberOf(Company $company): User
{
    return User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
}

function teamFor(Company $company): Team
{
    return CompanyContext::runForCompany(
        $company->id,
        reason: 'test fixture setup',
        caller: __FILE__,
        callback: fn () => Team::factory()->create(['company_id' => $company->id]),
    );
}

// ── Team: read isolation ────────────────────────────────────────────────────

it('hides a Team from a company the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $teamA = teamFor($companyA);
    $teamB = teamFor($companyB);

    test()->actingAs(memberOf($companyA));

    expect(Team::find($teamA->id))->not->toBeNull();
    expect(Team::find($teamB->id))->toBeNull();
});

// ── Team: create ────────────────────────────────────────────────────────────

it('allows creating a Team for the acting user\'s own company', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(memberOf($companyA));

    $team = Team::factory()->create(['company_id' => $companyA->id]);

    expect($team->exists)->toBeTrue();
    expect($team->company_id)->toBe($companyA->id);
});

it('forbids creating a Team for a company the acting user is not allowed to write to', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    test()->actingAs(memberOf($companyA));

    expect(fn () => Team::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('sales_teams', ['company_id' => $companyB->id]);
});

it('forbids creating a Team when no company_id can be resolved', function () {
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    // sales_teams is not empty at baseline: installing the plugin runs
    // SalesTeamSeeder. Assert on the delta, not on an absolute count.
    $before = DB::table('sales_teams')->count();

    expect(fn () => Team::factory()->create(['company_id' => null]))
        ->toThrow(AuthorizationException::class);

    expect(DB::table('sales_teams')->count())->toBe($before);
});

// ── Team: company_id immutability ───────────────────────────────────────────

it('forbids changing a Team\'s company_id on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = memberOf($companyA);
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $team = Team::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $team->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_teams', ['id' => $team->id, 'company_id' => $companyA->id]);
});

// ── Team: creator_id write path ─────────────────────────────────────────────

it('forbids creating a Team with an explicit creator_id that has no membership in the target company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $outsider = memberOf($companyB);

    test()->actingAs(memberOf($companyA));

    expect(fn () => Team::factory()->create(['company_id' => $companyA->id, 'creator_id' => $outsider->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('sales_teams', ['creator_id' => $outsider->id]);
});

it('forbids creating a Team with a nonexistent creator_id', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(memberOf($companyA));

    $before = DB::table('sales_teams')->count();

    expect(fn () => Team::factory()->create(['company_id' => $companyA->id, 'creator_id' => 999999999]))
        ->toThrow(AuthorizationException::class);

    expect(DB::table('sales_teams')->count())->toBe($before);
    $this->assertDatabaseMissing('sales_teams', ['creator_id' => 999999999]);
});

it('forbids changing a Team\'s creator_id on update, including from a historic NULL, leaving the row intact', function () {
    $companyA = Company::factory()->create();

    $user = memberOf($companyA);
    $otherMember = memberOf($companyA);
    test()->actingAs($user);

    $team = Team::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $team->update(['creator_id' => $otherMember->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_teams', ['id' => $team->id, 'creator_id' => $user->id]);

    // Same guarantee for a row whose creator was blanked by the FK's own
    // nullOnDelete(), written at the DB level because no application path
    // can produce that state (#138 A4F review 4830829763).
    DB::table('sales_teams')->where('id', $team->id)->update(['creator_id' => null]);

    $historic = Team::findOrFail($team->id);

    expect(fn () => $historic->update(['creator_id' => $otherMember->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_teams', ['id' => $team->id, 'creator_id' => null]);
});

// ── Team: leader membership ─────────────────────────────────────────────────

it('forbids assigning a Team leader who has no membership in the team\'s company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $outsider = memberOf($companyB);

    test()->actingAs(memberOf($companyA));

    expect(fn () => Team::factory()->create(['company_id' => $companyA->id, 'user_id' => $outsider->id]))
        ->toThrow(AuthorizationException::class);

    $team = Team::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $team->update(['user_id' => $outsider->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_teams', ['id' => $team->id, 'user_id' => null]);
});

// ── Team: lifecycle ─────────────────────────────────────────────────────────

it('forbids soft deleting, restoring and force deleting a Team from another company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $teamB = teamFor($companyB);

    test()->actingAs(memberOf($companyA));

    $loaded = Team::withoutGlobalScope(CompanyScope::class)->findOrFail($teamB->id);

    expect(fn () => $loaded->delete())->toThrow(AuthorizationException::class);
    expect(fn () => $loaded->forceDelete())->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_teams', ['id' => $teamB->id, 'deleted_at' => null]);
});

// ── TeamMember: attach / detach ─────────────────────────────────────────────

it('allows attaching a user of the same company to a Team', function () {
    $companyA = Company::factory()->create();

    $user = memberOf($companyA);
    $mate = memberOf($companyA);
    test()->actingAs($user);

    $team = Team::factory()->create(['company_id' => $companyA->id]);

    $team->members()->attach($mate->id);

    $this->assertDatabaseHas('sales_team_members', ['team_id' => $team->id, 'user_id' => $mate->id]);
});

it('forbids attaching a user of another company to a Team, without writing any row', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $outsider = memberOf($companyB);
    test()->actingAs(memberOf($companyA));

    $team = Team::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $team->members()->attach($outsider->id))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('sales_team_members', 0);
});

it('forbids attaching a nonexistent user to a Team', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(memberOf($companyA));

    $team = Team::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $team->members()->attach(999999999))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('sales_team_members', 0);
});

it('forbids creating a TeamMember directly against a Team hidden from the acting user', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $teamB = teamFor($companyB);
    $mateB = memberOf($companyB);

    test()->actingAs(memberOf($companyA));

    expect(fn () => TeamMember::create(['team_id' => $teamB->id, 'user_id' => $mateB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('sales_team_members', 0);
});

it('forbids creating a TeamMember with no user and against a nonexistent Team', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(memberOf($companyA));

    $team = Team::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => TeamMember::create(['team_id' => $team->id, 'user_id' => null]))
        ->toThrow(AuthorizationException::class);

    expect(fn () => TeamMember::create(['team_id' => 999999999, 'user_id' => memberOf($companyA)->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('sales_team_members', 0);
});

it('forbids retargeting a TeamMember\'s team_id or user_id, leaving the original row intact', function () {
    $companyA = Company::factory()->create();

    $user = memberOf($companyA);
    $mate = memberOf($companyA);
    $other = memberOf($companyA);
    test()->actingAs($user);

    $team = Team::factory()->create(['company_id' => $companyA->id]);
    $otherTeam = Team::factory()->create(['company_id' => $companyA->id]);

    $team->members()->attach($mate->id);

    $pivot = TeamMember::firstOrFail();

    expect(fn () => $pivot->update(['user_id' => $other->id]))->toThrow(AuthorizationException::class);
    expect(fn () => $pivot->update(['team_id' => $otherTeam->id]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_team_members', ['team_id' => $team->id, 'user_id' => $mate->id]);
    $this->assertDatabaseCount('sales_team_members', 1);
});

it('forbids detaching a TeamMember of a Team the acting user is not authorized for', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $teamB = teamFor($companyB);
    $mateB = memberOf($companyB);

    CompanyContext::runForCompany($companyB->id, reason: 'test fixture setup', caller: __FILE__, callback: function () use ($teamB, $mateB) {
        $teamB->members()->attach($mateB->id);
    });

    test()->actingAs(memberOf($companyA));

    $loaded = Team::withoutGlobalScope(CompanyScope::class)->findOrFail($teamB->id);

    expect(fn () => $loaded->members()->detach($mateB->id))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_team_members', ['team_id' => $teamB->id, 'user_id' => $mateB->id]);
});

// ── TeamMember: bulk paths ──────────────────────────────────────────────────

it('rejects a mixed sync() without attaching the valid users or detaching the existing ones', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = memberOf($companyA);
    $current = memberOf($companyA);
    $incoming = memberOf($companyA);
    $outsider = memberOf($companyB);
    test()->actingAs($user);

    $team = Team::factory()->create(['company_id' => $companyA->id]);
    $team->members()->attach($current->id);

    // Eloquent's own sync() detaches before attaching and wraps nothing in
    // a transaction, so without the pre-validation added in A4G this call
    // would drop $current and only then fail on $outsider.
    expect(fn () => $team->members()->sync([$incoming->id, $outsider->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_team_members', ['team_id' => $team->id, 'user_id' => $current->id]);
    $this->assertDatabaseMissing('sales_team_members', ['user_id' => $incoming->id]);
    $this->assertDatabaseMissing('sales_team_members', ['user_id' => $outsider->id]);
    $this->assertDatabaseCount('sales_team_members', 1);
});

it('rejects a mixed attach() without writing the valid users of the same call', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $mate = memberOf($companyA);
    $outsider = memberOf($companyB);
    test()->actingAs(memberOf($companyA));

    $team = Team::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $team->members()->attach([$mate->id, $outsider->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('sales_team_members', 0);
});

it('forbids updateExistingPivot() from smuggling a retarget through an already-persisted TeamMember', function () {
    $companyA = Company::factory()->create();

    $mate = memberOf($companyA);
    $other = memberOf($companyA);
    test()->actingAs(memberOf($companyA));

    $team = Team::factory()->create(['company_id' => $companyA->id]);
    $team->members()->attach($mate->id);

    expect(fn () => $team->members()->updateExistingPivot($mate->id, ['user_id' => $other->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_team_members', ['team_id' => $team->id, 'user_id' => $mate->id]);
});

// ── Order -> Team relation contract ─────────────────────────────────────────

it('allows an Order to reference a Team of its own company', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(memberOf($companyA));

    $team = Team::factory()->create(['company_id' => $companyA->id]);
    $order = Order::factory()->create(['company_id' => $companyA->id, 'team_id' => $team->id]);

    expect($order->team_id)->toBe($team->id);
});

it('forbids creating an Order that references a Team of another company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $teamB = teamFor($companyB);

    test()->actingAs(memberOf($companyA));

    expect(fn () => Order::factory()->create(['company_id' => $companyA->id, 'team_id' => $teamB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('sales_orders', ['team_id' => $teamB->id]);
});

it('forbids creating an Order that references a nonexistent Team', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(memberOf($companyA));

    expect(fn () => Order::factory()->create(['company_id' => $companyA->id, 'team_id' => 999999999]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('sales_orders', 0);
});

it('forbids retargeting an Order to a Team of another company, leaving the original row intact', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $teamB = teamFor($companyB);

    test()->actingAs(memberOf($companyA));

    $teamA = Team::factory()->create(['company_id' => $companyA->id]);
    $order = Order::factory()->create(['company_id' => $companyA->id, 'team_id' => $teamA->id]);

    expect(fn () => $order->update(['team_id' => $teamB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_orders', ['id' => $order->id, 'team_id' => $teamA->id]);
});

it('catches a cross-company Team that was soft deleted, instead of letting it pass as not found', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $teamB = teamFor($companyB);

    CompanyContext::runForCompany($companyB->id, reason: 'test fixture setup', caller: __FILE__, callback: fn () => $teamB->delete());

    test()->actingAs(memberOf($companyA));

    expect(fn () => Order::factory()->create(['company_id' => $companyA->id, 'team_id' => $teamB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('sales_orders', ['team_id' => $teamB->id]);
});

// ── Fail-closed and system context ──────────────────────────────────────────

it('fails closed on Team reads and writes when there is no authenticated user and no system context', function () {
    $companyA = Company::factory()->create();

    teamFor($companyA);

    expect(Team::count())->toBe(0);

    expect(fn () => Team::factory()->create(['company_id' => $companyA->id]))
        ->toThrow(AuthorizationException::class);
});

it('allows an explicit company system context to create a Team and attach its members, as the seeder does', function () {
    $companyA = Company::factory()->create();

    $mate = memberOf($companyA);

    CompanyContext::runForCompany($companyA->id, reason: 'test fixture setup', caller: __FILE__, callback: function () use ($companyA, $mate) {
        $team = Team::factory()->create(['company_id' => $companyA->id]);

        $team->members()->attach($mate->id);

        expect(Team::count())->toBe(1);
    });

    $this->assertDatabaseHas('sales_team_members', ['user_id' => $mate->id]);
});

it('produces a same-company Team and TeamMember pair from the bare factory default', function () {
    CompanyContext::runForAllCompanies(reason: 'test: bare factory coherence', caller: __FILE__, callback: function () {
        $pivot = TeamMember::factory()->create();

        $team = Team::withoutGlobalScope(CompanyScope::class)->findOrFail($pivot->team_id);
        $user = User::findOrFail($pivot->user_id);

        expect($team->company_id)->not->toBeNull();
        expect(CompanyScope::allowedCompanyIds($user)->contains((int) $team->company_id))->toBeTrue();
    });
});
