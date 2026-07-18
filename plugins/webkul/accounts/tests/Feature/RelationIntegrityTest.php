<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\PaymentType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Account\Models\FiscalPosition;
use Webkul\Account\Models\FiscalPositionAccount;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\PartialReconcile;
use Webkul\Account\Models\Partner;
use Webkul\Account\Models\Payment;
use Webkul\Account\Models\PaymentMethod;
use Webkul\Account\Models\PaymentMethodLine;
use Webkul\Account\Models\Product;
use Webkul\Account\Models\Tax;
use Webkul\Account\Models\TaxPartition;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Services\CompanyContext;

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

    // Seeded under a system context, not the companyA-only actingAs user
    // below — this test is about READ isolation for that user; attaching
    // companyB to their allowedCompanies instead (so the factory's own
    // nested Journal write-authorization succeeds) would also make
    // companyB legitimately visible to them, defeating the very isolation
    // being asserted (#138 review round 2, 2026-07-18: MoveFactory's
    // afterCreating() creates a Journal under company_id=$move->company_id,
    // which now requires write authorization for that company).
    //
    // Created directly as the base Move — same `accounts_account_moves`
    // row either alias would read/write, since neither declares its own
    // table, boot(), or fillable override.
    $moveB = CompanyContext::runForAllCompanies(
        reason: 'test: seed a cross-company Move for the read-isolation check below',
        caller: __FILE__,
        callback: fn () => Move::factory()->create(['company_id' => $companyB->id]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(\Webkul\Accounting\Models\Invoice::find($moveB->id))->toBeNull();
    expect(\Webkul\Invoice\Models\Invoice::find($moveB->id))->toBeNull();
});

// ── CompanyScope::assertCanWriteCompany(): write-side authorization (#138 review round 2, 2026-07-18) ──

it('forbids a user in company A from creating a Journal directly under company B by knowing its id', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Journal::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('accounts_journals', ['company_id' => $companyB->id]);
});

it('forbids a user in company A from creating a BankStatementLine anchored to a BankStatement hidden in company B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userB = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));
    test()->actingAs($userB);
    $statementB = BankStatement::create(['company_id' => $companyB->id]);

    $userA = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($userA);

    // companyA's user cannot even see statementB (CompanyScope hides it),
    // but resolveEffectiveCompanyIdOrFail() resolves parents unscoped —
    // the write-authorization check inside it is what must still reject
    // this, not read isolation.
    expect(BankStatement::find($statementB->id))->toBeNull();

    $line = new BankStatementLine;
    $line->statement_id = $statementB->id;

    expect(fn () => $line->save())->toThrow(AuthorizationException::class);
});

it('forbids an explicit company_id outside the active CompanyContext::company scope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    expect(fn () => CompanyContext::runForCompany(
        $companyA->id,
        reason: 'test: write authorization under a company-mode context',
        caller: __FILE__,
        callback: fn () => Journal::factory()->create(['company_id' => $companyB->id]),
    ))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('accounts_journals', ['company_id' => $companyB->id]);
});

it('fails closed when creating a strict_company owner with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();

    expect(fn () => Journal::factory()->create(['company_id' => $company->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('accounts_journals', ['company_id' => $company->id]);
});

// ── Payment::computeDestinationAccountId(): must filter by company before first() (#138 review round 2, 2026-07-18) ──

it('selects a destination Account enabled for its own company instead of the first matching Account created earlier', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $journalA = Journal::factory()->create(['company_id' => $companyA->id]);

    // Account B created first — would win a bare first() — but never
    // enabled for companyA.
    $accountB = Account::factory()->receivable()->create();

    // Account A created after, explicitly enabled for companyA.
    $accountA = Account::factory()->receivable()->create();
    $accountA->companies()->attach($companyA->id);

    $outstandingAccount = Account::factory()->create();
    $outstandingAccount->companies()->attach($companyA->id);

    $partner = Partner::factory()->create();
    $currency = Currency::factory()->create();

    // Payment, PaymentMethod and PaymentMethodLine's own factories are
    // unusable here: PaymentMethod/PaymentMethodLine have no newFactory()
    // override, and their definition() arrays call the bare
    // PaymentMethod::factory()/PaymentMethodLine::factory() convention
    // lookup UNCONDITIONALLY while building the raw attribute array —
    // before any ->create([...]) override is merged in — so even
    // overriding those keys can't prevent the crash. Building every row
    // with plain ::create() instead avoids the factory system entirely.
    $paymentMethod = PaymentMethod::create([
        'code'         => 'TEST',
        'payment_type' => PaymentType::RECEIVE,
        'name'         => 'Test Method',
    ]);

    $paymentMethodLine = PaymentMethodLine::create([
        'payment_method_id' => $paymentMethod->id,
        'journal_id'        => $journalA->id,
        'name'              => 'Test Line',
    ]);

    $payment = Payment::create([
        'company_id'             => $companyA->id,
        'journal_id'             => $journalA->id,
        'partner_id'             => $partner->id,
        'partner_type'           => 'customer',
        'payment_type'           => PaymentType::RECEIVE,
        'currency_id'            => $currency->id,
        'date'                   => now(),
        'amount'                 => 100,
        'payment_method_id'      => $paymentMethod->id,
        'payment_method_line_id' => $paymentMethodLine->id,
        'outstanding_account_id' => $outstandingAccount->id,
        'destination_account_id' => null,
    ]);

    expect($payment->destination_account_id)->toBe($accountA->id)
        ->and($payment->destination_account_id)->not->toBe($accountB->id);
});

// ── Journal: no side effects from a rejected company change (#138 review round 2, 2026-07-18) ──

it('does not attach Accounts to a new company via ensureEnabledForCompany() when a rejected Journal company_id change is attempted', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    // Authorized for BOTH companies — this must still be rejected on
    // immutability grounds, not merely on write-authorization grounds.
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $accountA = Account::factory()->create();

    $journal = Journal::factory()->create([
        'company_id'         => $companyA->id,
        'default_account_id' => $accountA->id,
    ]);

    expect($accountA->companies()->where('companies.id', $companyB->id)->exists())->toBeFalse();

    expect(fn () => $journal->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    expect($accountA->companies()->where('companies.id', $companyB->id)->exists())->toBeFalse();
    $this->assertDatabaseHas('accounts_journals', ['id' => $journal->id, 'company_id' => $companyA->id]);
});

// ── BankStatement: standalone strict owner, immutable company_id (#138 review round 2, 2026-07-18) ──

it('forbids changing a standalone (no Journal) BankStatement\'s company_id directly, even for a user authorized in both companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $statement = BankStatement::create(['company_id' => $companyA->id]);

    expect(fn () => $statement->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('accounts_bank_statements', ['id' => $statement->id, 'company_id' => $companyA->id]);
});

it('forbids moving a Journal-anchored BankStatement to another company by reassigning journal_id, even when the new company_id would be consistent', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $journalA = Journal::factory()->create(['company_id' => $companyA->id]);
    $journalB = Journal::factory()->create(['company_id' => $companyB->id]);

    $statement = BankStatement::create(['journal_id' => $journalA->id]);

    expect($statement->company_id)->toBe($companyA->id);

    // Consistently updating both journal_id and company_id to B must still
    // be rejected — a mismatch-only check would miss this, since the new
    // pair is internally consistent (#138 review round 2, 2026-07-18).
    expect(fn () => $statement->update(['journal_id' => $journalB->id, 'company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('accounts_bank_statements', ['id' => $statement->id, 'journal_id' => $journalA->id, 'company_id' => $companyA->id]);
});
