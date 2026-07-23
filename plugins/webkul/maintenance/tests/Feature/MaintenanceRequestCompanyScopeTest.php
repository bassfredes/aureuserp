<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Maintenance\Enums\MaintenanceRepeatType;
use Webkul\Maintenance\Enums\MaintenanceRepeatUnit;
use Webkul\Maintenance\Enums\MaintenanceRequestType;
use Webkul\Maintenance\Models\Equipment;
use Webkul\Maintenance\Models\EquipmentCategory;
use Webkul\Maintenance\Models\MaintenanceRequest;
use Webkul\Maintenance\Models\Stage;
use Webkul\Maintenance\Models\Team;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('maintenance');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// ── company_id obligatorio, autorización en create ──────────────────────

it('fails closed when creating a MaintenanceRequest with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();
    $team = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Team::factory()->create(['company_id' => $company->id]));

    expect(fn () => MaintenanceRequest::factory()->create(['company_id' => $company->id, 'maintenance_team_id' => $team->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('maintenance_requests', ['company_id' => $company->id]);
});

it('forbids a user in company A from creating a MaintenanceRequest directly under company B by knowing its id', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $teamB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Team::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => MaintenanceRequest::factory()->create(['company_id' => $companyB->id, 'maintenance_team_id' => $teamB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('maintenance_requests', ['company_id' => $companyB->id]);
});

// ── inmutable, reautorización en cada save ───────────────────────────────

it('forbids changing a MaintenanceRequest\'s company_id, even for a user authorized in both companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $teamA = Team::factory()->create(['company_id' => $companyA->id]);
    $request = MaintenanceRequest::factory()->create(['company_id' => $companyA->id, 'maintenance_team_id' => $teamA->id]);

    expect(fn () => $request->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('maintenance_requests', ['id' => $request->id, 'company_id' => $companyA->id]);
});

it('forbids a user in company A from updating an unrelated field on a MaintenanceRequest obtained from company B via an unscoped query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $requestB = CompanyContext::runForAllCompanies(
        reason: 'fixture', caller: __FILE__,
        callback: fn () => MaintenanceRequest::factory()->create(['company_id' => $companyB->id]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $requestBUnscoped = MaintenanceRequest::withoutGlobalScope(CompanyScope::class)->findOrFail($requestB->id);

    expect(fn () => $requestBUnscoped->update(['name' => 'Renamed by A']))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('maintenance_requests', ['id' => $requestB->id, 'name' => 'Renamed by A']);
});

// ── relation-integrity: equipment_id / maintenance_team_id / category_id must match company_id ──

it('forbids creating a MaintenanceRequest whose equipment_id belongs to a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $equipmentB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Equipment::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => MaintenanceRequest::factory()->create(['company_id' => $companyA->id, 'equipment_id' => $equipmentB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('maintenance_requests', ['equipment_id' => $equipmentB->id]);
});

it('forbids creating a MaintenanceRequest whose maintenance_team_id belongs to a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $teamB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Team::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => MaintenanceRequest::factory()->create(['company_id' => $companyA->id, 'maintenance_team_id' => $teamB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('maintenance_requests', ['maintenance_team_id' => $teamB->id]);
});

it('forbids creating a MaintenanceRequest whose category_id belongs to a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $categoryB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EquipmentCategory::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => MaintenanceRequest::factory()->create(['company_id' => $companyA->id, 'category_id' => $categoryB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('maintenance_requests', ['category_id' => $categoryB->id]);
});

it('allows creating a MaintenanceRequest whose equipment_id, maintenance_team_id and category_id all belong to the same company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $teamA = Team::factory()->create(['company_id' => $companyA->id]);
    $categoryA = EquipmentCategory::factory()->create(['company_id' => $companyA->id]);
    $equipmentA = Equipment::factory()->create(['company_id' => $companyA->id]);

    $request = MaintenanceRequest::factory()->create([
        'company_id'          => $companyA->id,
        'maintenance_team_id' => $teamA->id,
        'category_id'         => $categoryA->id,
        'equipment_id'        => $equipmentA->id,
    ]);

    expect($request->exists)->toBeTrue();
});

// ── recurring replica: preserves and re-authorizes the persisted company ──

it('replicates a done recurring MaintenanceRequest under the same, re-authorized company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $teamA = Team::factory()->create(['company_id' => $companyA->id]);
    $doneStage = Stage::factory()->create(['done' => true]);
    $openStage = Stage::factory()->create(['done' => false]);

    $request = MaintenanceRequest::factory()->create([
        'company_id'             => $companyA->id,
        'maintenance_team_id'    => $teamA->id,
        'maintenance_type'       => MaintenanceRequestType::PREVENTIVE,
        'recurring_maintenance'  => true,
        'repeat_interval'        => 1,
        'repeat_unit'            => MaintenanceRepeatUnit::MONTH,
        'repeat_type'            => MaintenanceRepeatType::FOREVER,
        'stage_id'               => $openStage->id,
        'scheduled_at'           => now(),
    ]);

    $request->update(['stage_id' => $doneStage->id]);

    $replica = MaintenanceRequest::query()
        ->where('company_id', $companyA->id)
        ->where('id', '!=', $request->id)
        ->latest('id')
        ->first();

    expect($replica)->not->toBeNull()
        ->and($replica->company_id)->toBe($companyA->id)
        ->and($replica->maintenance_team_id)->toBe($teamA->id);
});

it('forbids replicating a done recurring MaintenanceRequest when the acting context can no longer write its persisted company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $teamA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Team::factory()->create(['company_id' => $companyA->id]));
    $doneStage = Stage::factory()->create(['done' => true]);
    $openStage = Stage::factory()->create(['done' => false]);

    $request = CompanyContext::runForAllCompanies(
        reason: 'fixture', caller: __FILE__,
        callback: fn () => MaintenanceRequest::factory()->create([
            'company_id'            => $companyA->id,
            'maintenance_team_id'   => $teamA->id,
            'maintenance_type'      => MaintenanceRequestType::PREVENTIVE,
            'recurring_maintenance' => true,
            'repeat_interval'       => 1,
            'repeat_unit'           => MaintenanceRepeatUnit::MONTH,
            'repeat_type'           => MaintenanceRepeatType::FOREVER,
            'stage_id'              => $openStage->id,
            'scheduled_at'          => now(),
        ]),
    );

    // Acting user is only authorized for companyB — updating requestA's
    // stage (which they can only reach via an unscoped/withoutGlobalScope
    // query, same class of access as the other "obtained via unscoped
    // query" tests above) must not be able to spawn a same-company replica
    // on their behalf.
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));
    test()->actingAs($user);

    $requestAUnscoped = MaintenanceRequest::withoutGlobalScope(CompanyScope::class)->findOrFail($request->id);

    expect(fn () => $requestAUnscoped->update(['stage_id' => $doneStage->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('maintenance_requests', ['id' => $request->id, 'stage_id' => $doneStage->id]);
});

// ── read isolation (HasCompanyScope) ─────────────────────────────────────

it('lets a user see only MaintenanceRequests in their allowed companies', function () {
    $companyA = Company::factory()->create();
    $companyC = Company::factory()->create();

    $requestA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => MaintenanceRequest::factory()->create(['company_id' => $companyA->id]));
    $requestC = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => MaintenanceRequest::factory()->create(['company_id' => $companyC->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyA->id]);
    test()->actingAs($user);

    $visibleIds = MaintenanceRequest::query()->pluck('id');

    expect($visibleIds)->toContain($requestA->id)
        ->not->toContain($requestC->id);
});

it('shows an authenticated user with no allowed companies an empty MaintenanceRequest list', function () {
    Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    expect(MaintenanceRequest::query()->count())->toBe(0);
});
