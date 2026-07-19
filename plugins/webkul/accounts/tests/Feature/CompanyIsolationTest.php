<?php

use Webkul\Account\Models\Move;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

it('hides invoices/moves from companies the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));

    // No acting user yet — MoveFactory's own afterCreating() callback may
    // create a Journal under the target company, which now needs a system
    // context to be write-authorized (#138 review round 2, 2026-07-18).
    [$moveA, $moveB] = TestBootstrapHelper::withSystemContextIfNoUser(fn () => [
        Move::factory()->create(['company_id' => $companyA->id]),
        Move::factory()->create(['company_id' => $companyB->id]),
    ]);

    test()->actingAs($userA);

    expect(Move::find($moveA->id))->not->toBeNull();
    expect(Move::find($moveB->id))->toBeNull();
    expect(Move::query()->pluck('company_id')->all())->not->toContain($companyB->id);
});
