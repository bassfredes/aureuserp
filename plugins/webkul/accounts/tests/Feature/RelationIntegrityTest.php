<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Product;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// ── MoveLine: parent-anchored effective company (#138, D5b pattern, aureuserp#137) ─

it('forbids a MoveLine for a Move in company A referencing a Product from company B, even for a user allowed in both', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $moveA = Move::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->withAccounts()->create(['company_id' => $companyB->id]);

    expect(fn () => MoveLine::factory()->create([
        'move_id'    => $moveA->id,
        'product_id' => $productB->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('accounts_account_move_lines', ['move_id' => $moveA->id, 'product_id' => $productB->id]);
});

it('forbids changing a MoveLine\'s product_id to a Product from a different company on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $moveA = Move::factory()->create(['company_id' => $companyA->id]);
    $productA = Product::factory()->withAccounts()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->withAccounts()->create(['company_id' => $companyB->id]);

    $moveLine = MoveLine::factory()->create([
        'move_id'    => $moveA->id,
        'product_id' => $productA->id,
    ]);

    expect(fn () => $moveLine->update(['product_id' => $productB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('accounts_account_move_lines', ['id' => $moveLine->id, 'product_id' => $productA->id]);
});

it('derives a MoveLine.company_id from its parent Move, not the acting user\'s default', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $moveA = Move::factory()->create(['company_id' => $companyA->id]);

    $moveLine = MoveLine::factory()->create([
        'move_id'    => $moveA->id,
        'company_id' => $companyB->id,
    ]);

    expect($moveLine->company_id)->toBe($companyA->id);
});
