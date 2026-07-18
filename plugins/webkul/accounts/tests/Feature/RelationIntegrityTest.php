<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Account\Models\FiscalPosition;
use Webkul\Account\Models\FiscalPositionAccount;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\PartialReconcile;
use Webkul\Account\Models\Product;
use Webkul\Account\Models\Tax;
use Webkul\Account\Models\TaxPartition;
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
        'company_id' => null,
    ]);

    expect($moveLine->company_id)->toBe($companyA->id);
});

it('forbids an explicit MoveLine company_id that mismatches its Move\'s company (D3: no silent reassignment)', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $moveA = Move::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => MoveLine::factory()->create([
        'move_id'    => $moveA->id,
        'company_id' => $companyB->id,
    ]))->toThrow(AuthorizationException::class);
});

it('forbids reassigning a MoveLine to a Move from a different company on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $moveA = Move::factory()->create(['company_id' => $companyA->id]);
    $moveB = Move::factory()->create(['company_id' => $companyB->id]);

    $moveLine = MoveLine::factory()->create([
        'move_id'    => $moveA->id,
        'company_id' => null,
    ]);

    expect(fn () => $moveLine->update(['move_id' => $moveB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('accounts_account_move_lines', ['id' => $moveLine->id, 'move_id' => $moveA->id]);
});

it('forbids a MoveLine referencing an Account not enabled for its company (#138 review, 2026-07-18)', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $moveA = Move::factory()->create(['company_id' => $companyA->id]);
    // Deliberately not attached to accounts_account_companies for
    // companyA — Account has no company_id of its own.
    $unenabledAccount = Account::factory()->create();

    expect(fn () => MoveLine::factory()->create([
        'move_id'      => $moveA->id,
        'account_id'   => $unenabledAccount->id,
        // computeAccountId() only preserves an explicit account_id as-is
        // for LINE_SECTION/LINE_NOTE — any other display_type recomputes
        // it from the Journal/Product instead, masking this test.
        'display_type' => DisplayType::LINE_SECTION,
    ]))->toThrow(AuthorizationException::class);
});

// ── PartialReconcile: debit/credit MoveLines must share a company ──────────

it('forbids a PartialReconcile pairing a debit MoveLine from company A with a credit MoveLine from company B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $moveA = Move::factory()->create(['company_id' => $companyA->id]);
    $moveB = Move::factory()->create(['company_id' => $companyB->id]);
    $debitLine = MoveLine::factory()->create(['move_id' => $moveA->id]);
    $creditLine = MoveLine::factory()->create(['move_id' => $moveB->id]);

    expect(fn () => PartialReconcile::factory()->create([
        'debit_move_id'  => $debitLine->id,
        'credit_move_id' => $creditLine->id,
        'company_id'     => null,
    ]))->toThrow(AuthorizationException::class);
});

it('derives a PartialReconcile.company_id from its debit MoveLine, not the acting user\'s default', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $moveA = Move::factory()->create(['company_id' => $companyA->id]);
    $debitLine = MoveLine::factory()->create(['move_id' => $moveA->id]);
    $creditLine = MoveLine::factory()->create(['move_id' => $moveA->id]);

    $partial = PartialReconcile::factory()->create([
        'debit_move_id'  => $debitLine->id,
        'credit_move_id' => $creditLine->id,
        'company_id'     => null,
    ]);

    expect($partial->company_id)->toBe($companyA->id);
});

// ── FiscalPositionAccount: strict-derived company + Account pivot ──────────

it('forbids a FiscalPositionAccount referencing an Account not enabled for the FiscalPosition\'s company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $fiscalPosition = FiscalPosition::factory()->create(['company_id' => $companyA->id]);
    $unenabledAccount = Account::factory()->create();

    expect(fn () => FiscalPositionAccount::create([
        'fiscal_position_id' => $fiscalPosition->id,
        'account_source_id'  => $unenabledAccount->id,
    ]))->toThrow(AuthorizationException::class);
});

// ── BankStatementLine: strict-derived company from BankStatement ───────────

it('forbids an explicit BankStatementLine company_id that mismatches its BankStatement\'s company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $statementA = BankStatement::create(['company_id' => $companyA->id]);

    $line = new BankStatementLine;
    $line->statement_id = $statementA->id;
    $line->company_id = $companyB->id;

    expect(fn () => $line->save())->toThrow(AuthorizationException::class);
});

it('derives a BankStatementLine.company_id from its parent BankStatement', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $statementA = BankStatement::create(['company_id' => $companyA->id]);

    $line = new BankStatementLine;
    $line->statement_id = $statementA->id;
    $line->save();

    expect($line->company_id)->toBe($companyA->id);
});

// ── TaxPartition: strict-derived company from Tax + Account pivot ──────────

it('forbids an explicit TaxPartition company_id that mismatches its Tax\'s company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $taxA = Tax::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => TaxPartition::factory()->create([
        'tax_id'     => $taxA->id,
        'company_id' => $companyB->id,
        'account_id' => null,
    ]))->toThrow(AuthorizationException::class);
});

it('forbids a TaxPartition referencing an Account not enabled for its Tax\'s company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $taxA = Tax::factory()->create(['company_id' => $companyA->id]);
    $unenabledAccount = Account::factory()->create();

    expect(fn () => TaxPartition::factory()->create([
        'tax_id'     => $taxA->id,
        'company_id' => null,
        'account_id' => $unenabledAccount->id,
    ]))->toThrow(AuthorizationException::class);
});

// ── Move::computePaymentState(): unaffected by PartialReconcile's new strict invariants ──

it('computes Move::computePaymentState() without error against a properly company-scoped PartialReconcile', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $receivable = Account::factory()->receivable()->create();
    $receivable->companies()->syncWithoutDetaching([$companyA->id]);

    $moveA = Move::factory()->posted()->invoice()->create(['company_id' => $companyA->id]);
    $moveB = Move::factory()->posted()->create(['company_id' => $companyA->id]);

    $debitLine = MoveLine::factory()->create([
        'move_id'      => $moveA->id,
        'account_id'   => $receivable->id,
        // preserves the explicit account_id override through computeAccountId()
        'display_type' => DisplayType::LINE_SECTION,
    ]);

    $creditLine = MoveLine::factory()->create(['move_id' => $moveB->id]);

    PartialReconcile::factory()->create([
        'debit_move_id'  => $debitLine->id,
        'credit_move_id' => $creditLine->id,
        'company_id'     => null,
    ]);

    expect(fn () => $moveA->computePaymentState())->not->toThrow(\Throwable::class);
    expect($moveA->payment_state)->not->toBeNull();
});

// ── accounting/invoices alias models: inherit HasCompanyScope via Eloquent's own boot() late static binding, with no code of their own (Webkul\Accounting\Models\Invoice / Webkul\Invoice\Models\Invoice both `extends Move` with an empty body) ──

it('applies HasCompanyScope read isolation to the accounting and invoices plugins\' Invoice alias models', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    // Created directly as the base Move — same `accounts_account_moves`
    // row either alias would read/write, since neither declares its own
    // table, boot(), or fillable override.
    $moveB = Move::factory()->create(['company_id' => $companyB->id]);

    expect(\Webkul\Accounting\Models\Invoice::find($moveB->id))->toBeNull();
    expect(\Webkul\Invoice\Models\Invoice::find($moveB->id))->toBeNull();
});
