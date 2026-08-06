<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Webkul\Sale\Http\Requests\OrderRequest;
use Webkul\Sale\Models\Order;
use Webkul\Sale\Models\Tag;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';

/**
 * #138 PR4 A4K: Tag — strict_company, no shared rows (2026-08-03
 * adversarial design review). No company_or_shared contract and no
 * Calendar-style default seeder exists for tags, so a tag used by orders
 * from more than one company is a genuine data conflict, and an orphan tag
 * (no orders) must never have its company inferred from its creator. Same
 * HasCompanyScope + HasStrictCompanyId contract as Journal/PaymentTerm/
 * PaymentToken (#138 PR4 A4J), plus the company_id column, the
 * BackfillTagCompanyId preflight/backfill command, the per-company name
 * uniqueness in TagRequest, and the CompanyScope-respecting tag existence
 * check in OrderRequest.
 */
beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('sales');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

function tagScopeMemberOf(Company $company): User
{
    return User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
}

function tagFor(Company $company, array $overrides = []): Tag
{
    return CompanyContext::runForCompany(
        $company->id,
        reason: 'test fixture setup',
        caller: __FILE__,
        callback: fn () => Tag::factory()->create(['company_id' => $company->id, ...$overrides]),
    );
}

function orderFor(Company $company): Order
{
    test()->actingAs(tagScopeMemberOf($company));

    return Order::factory()->create(['company_id' => $company->id]);
}

function attachTagToOrder(Order $order, int $tagId): void
{
    DB::table('sales_order_tags')->insert(['order_id' => $order->id, 'tag_id' => $tagId]);
}

/**
 * Historical row with no company_id at all, inserted below the ORM on
 * purpose: this is exactly the pre-migration state BackfillTagCompanyId
 * exists to fix, and Tag's HasStrictCompanyId now refuses to ever persist
 * a company-less row through Eloquent.
 */
function legacyTagRow(?string $name = null): int
{
    return DB::table('sales_tags')->insertGetId([
        'name'       => $name ?? fake()->unique()->words(2, true),
        'color'      => fake()->hexColor(),
        'creator_id' => null,
        'company_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function orderRequestTagsRules(): array
{
    $rules = (new OrderRequest)->rules();

    return [
        'sales_order_tags'   => $rules['sales_order_tags'],
        'sales_order_tags.*' => $rules['sales_order_tags.*'],
    ];
}

function tagScopeApiUser(Company $company, array $permissions = []): User
{
    $user = SecurityHelper::authenticateWithPermissions($permissions);

    $user->forceFill(['default_company_id' => $company->id])->saveQuietly();
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    Sanctum::actingAs($user, ['*']);

    return $user;
}

function tagScopeRoute(string $action, mixed $tag = null): string
{
    $name = "admin.api.v1.sales.tags.{$action}";

    return $tag ? route($name, $tag) : route($name);
}

// ── read isolation ───────────────────────────────────────────────────────

it('hides a Tag from a company the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $tagA = tagFor($companyA);
    $tagB = tagFor($companyB);

    test()->actingAs(tagScopeMemberOf($companyA));

    expect(Tag::find($tagA->id))->not->toBeNull();
    expect(Tag::find($tagB->id))->toBeNull();
});

it('shows a multi-company actor tags from both allowed companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $companyC = Company::factory()->create();

    $tagA = tagFor($companyA);
    $tagB = tagFor($companyB);
    $tagC = tagFor($companyC);

    $user = tagScopeMemberOf($companyA);
    $user->allowedCompanies()->syncWithoutDetaching([$companyB->id]);
    test()->actingAs($user);

    $ids = Tag::query()->pluck('id');

    expect($ids)->toContain($tagA->id, $tagB->id)
        ->not->toContain($tagC->id);
});

// ── create ───────────────────────────────────────────────────────────────

it('allows creating a Tag for the acting user\'s own company', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(tagScopeMemberOf($companyA));

    $tag = Tag::factory()->create(['company_id' => $companyA->id]);

    expect($tag->exists)->toBeTrue();
    expect($tag->company_id)->toBe($companyA->id);
});

it('forbids creating a Tag for a company the acting user is not allowed to write to', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    test()->actingAs(tagScopeMemberOf($companyA));

    expect(fn () => Tag::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('sales_tags', ['company_id' => $companyB->id]);
});

it('forbids creating a Tag when no company_id can be resolved', function () {
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    $before = DB::table('sales_tags')->count();

    expect(fn () => Tag::factory()->create(['company_id' => null]))
        ->toThrow(AuthorizationException::class);

    expect(DB::table('sales_tags')->count())->toBe($before);
});

it('defaults company_id from the acting user when none is given', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(tagScopeMemberOf($companyA));

    $tag = Tag::factory()->create(['company_id' => null]);

    expect($tag->company_id)->toBe($companyA->id);
});

// ── immutability ─────────────────────────────────────────────────────────

it('forbids changing a Tag\'s company_id on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = tagScopeMemberOf($companyA);
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $tag = Tag::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $tag->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_tags', ['id' => $tag->id, 'company_id' => $companyA->id]);
});

it('forbids updating a Tag that belongs to another company, even for a harmless field', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $tagB = tagFor($companyB);

    test()->actingAs(tagScopeMemberOf($companyA));

    $loaded = Tag::withoutGlobalScope(CompanyScope::class)->findOrFail($tagB->id);

    expect(fn () => $loaded->update(['color' => '#000000']))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_tags', ['id' => $tagB->id, 'company_id' => $companyB->id]);
});

// ── delete ───────────────────────────────────────────────────────────────

it('forbids deleting a Tag from a different company than the acting user', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $tagB = tagFor($companyB);

    test()->actingAs(tagScopeMemberOf($companyA));

    $loaded = Tag::withoutGlobalScope(CompanyScope::class)->findOrFail($tagB->id);

    expect(fn () => $loaded->delete())->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_tags', ['id' => $tagB->id]);
});

it('allows deleting a Tag from the acting user\'s own company', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(tagScopeMemberOf($companyA));

    $tag = Tag::factory()->create(['company_id' => $companyA->id]);
    $tag->delete();

    $this->assertDatabaseMissing('sales_tags', ['id' => $tag->id]);
});

it('forbids deleting a legacy orphan Tag with no company_id even from console/system context', function () {
    $tagId = legacyTagRow();

    $tag = Tag::withoutGlobalScope(CompanyScope::class)->findOrFail($tagId);

    expect(fn () => $tag->delete())->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_tags', ['id' => $tagId]);
});

// ── fail closed and system context ─────────────────────────────────────

it('fails closed on Tag reads and writes when there is no authenticated user and no system context', function () {
    $companyA = Company::factory()->create();

    tagFor($companyA);

    expect(Tag::count())->toBe(0);

    expect(fn () => Tag::factory()->create(['company_id' => $companyA->id]))
        ->toThrow(AuthorizationException::class);
});

it('allows an explicit company system context to create a Tag', function () {
    $companyA = Company::factory()->create();

    CompanyContext::runForCompany($companyA->id, reason: 'test fixture setup', caller: __FILE__, callback: function () use ($companyA) {
        $tag = Tag::factory()->create(['company_id' => $companyA->id]);

        expect($tag->company_id)->toBe($companyA->id);
        expect(Tag::count())->toBe(1);
    });
});

// ── name uniqueness scoped per company (TagRequest) ─────────────────────

it('allows the same Tag name in two different companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    tagFor($companyA, ['name' => 'Urgent']);

    // A duplicate name is fine as long as it belongs to a DIFFERENT
    // company — Rule::unique() is scoped to the effective company, not
    // global (#138 PR4 A4K).
    $tagB = tagFor($companyB, ['name' => 'Urgent']);

    expect($tagB->name)->toBe('Urgent');
    $this->assertDatabaseCount('sales_tags', 2);
});

it('rejects a duplicate Tag name within the acting user\'s own company on create', function () {
    $companyA = Company::factory()->create();

    tagFor($companyA, ['name' => 'Urgent']);

    tagScopeApiUser($companyA, ['create_sale_tag']);

    $this->postJson(tagScopeRoute('store'), ['name' => 'Urgent', 'color' => '#00FF00'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('rejects a duplicate Tag name on update within the same company but allows keeping its own name', function () {
    $companyA = Company::factory()->create();

    tagFor($companyA, ['name' => 'Foo']);
    $tagBar = tagFor($companyA, ['name' => 'Bar']);

    tagScopeApiUser($companyA, ['update_sale_tag']);

    $this->patchJson(tagScopeRoute('update', $tagBar), ['name' => 'Foo'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $this->patchJson(tagScopeRoute('update', $tagBar), ['name' => 'Bar'])
        ->assertOk();
});

// ── OrderRequest: sales_order_tags must respect Tag's CompanyScope ──────

it('rejects an OrderRequest sales_order_tags id belonging to a different company than the acting user', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $tagB = tagFor($companyB);

    test()->actingAs(tagScopeMemberOf($companyA));

    $validator = Validator::make(
        ['sales_order_tags' => [$tagB->id]],
        orderRequestTagsRules(),
    );

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('sales_order_tags.0'))->toBeTrue();
});

it('accepts an OrderRequest sales_order_tags id belonging to the acting user\'s own company', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(tagScopeMemberOf($companyA));

    $tagA = Tag::factory()->create(['company_id' => $companyA->id]);

    $validator = Validator::make(
        ['sales_order_tags' => [$tagA->id]],
        orderRequestTagsRules(),
    );

    expect($validator->fails())->toBeFalse();
});

// ── BackfillTagCompanyId: preflight + backfill command ──────────────────

it('backfills a Tag\'s company_id from the single company of its associated orders', function () {
    $companyA = Company::factory()->create();

    $resolvableTagId = legacyTagRow();
    $orphanTagId = legacyTagRow();

    $orderA1 = orderFor($companyA);
    $orderA2 = orderFor($companyA);

    attachTagToOrder($orderA1, $resolvableTagId);
    attachTagToOrder($orderA2, $resolvableTagId);

    $this->artisan('sales:tags:backfill-company')
        ->assertExitCode(0);

    $this->assertDatabaseHas('sales_tags', ['id' => $resolvableTagId, 'company_id' => $companyA->id]);
    $this->assertDatabaseHas('sales_tags', ['id' => $orphanTagId, 'company_id' => null]);
});

it('aborts the whole backfill run, writing nothing, when a Tag is used by orders from more than one company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $conflictTagId = legacyTagRow();
    $resolvableTagId = legacyTagRow();

    $orderA = orderFor($companyA);
    $orderB = orderFor($companyB);

    attachTagToOrder($orderA, $conflictTagId);
    attachTagToOrder($orderB, $conflictTagId);
    attachTagToOrder($orderA, $resolvableTagId);

    $this->artisan('sales:tags:backfill-company')
        ->assertExitCode(1);

    // Real conflict aborts the ENTIRE run, not just the conflicting tag —
    // the otherwise-resolvable tag must also stay untouched.
    $this->assertDatabaseHas('sales_tags', ['id' => $conflictTagId, 'company_id' => null]);
    $this->assertDatabaseHas('sales_tags', ['id' => $resolvableTagId, 'company_id' => null]);
});

it('never infers an orphan Tag\'s company from its creator\'s default company', function () {
    $company = Company::factory()->create();
    $creator = tagScopeMemberOf($company);

    $orphanTagId = DB::table('sales_tags')->insertGetId([
        'name'       => fake()->unique()->words(2, true),
        'color'      => fake()->hexColor(),
        'creator_id' => $creator->id,
        'company_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('sales:tags:backfill-company')
        ->assertExitCode(0);

    $this->assertDatabaseHas('sales_tags', ['id' => $orphanTagId, 'company_id' => null]);
});

it('writes nothing in --dry-run mode even when the preflight is clean', function () {
    $companyA = Company::factory()->create();

    $resolvableTagId = legacyTagRow();
    $order = orderFor($companyA);
    attachTagToOrder($order, $resolvableTagId);

    $this->artisan('sales:tags:backfill-company', ['--dry-run' => true])
        ->assertExitCode(0);

    $this->assertDatabaseHas('sales_tags', ['id' => $resolvableTagId, 'company_id' => null]);
});
