<?php

use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\JournalAccount;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

/**
 * JournalAccount::$timestamps was declared `protected`, narrowing the
 * parent Eloquent Model's `public $timestamps` — a PHP fatal at class-load
 * time (Intelligent-Integration-Suite#138 audit, PR 0 prerequisite #1).
 * Reflection alone reproduces it; this also proves the pivot still behaves
 * correctly (no timestamp columns written) once the visibility is fixed.
 */
it('loads JournalAccount without a PHP fatal and keeps timestamps disabled', function () {
    expect(fn () => new ReflectionClass(JournalAccount::class))->not->toThrow(\Throwable::class);

    // No acting user yet — Journal's write-authorization check needs a
    // system context for this fixture (#138 review round 2, 2026-07-18).
    [$account, $journal] = TestBootstrapHelper::withSystemContextIfNoUser(fn () => [
        Account::factory()->create(),
        Journal::factory()->create(),
    ]);

    $pivot = JournalAccount::create([
        'account_id' => $account->id,
        'journal_id' => $journal->id,
    ]);

    expect($pivot->timestamps)->toBeFalse()
        ->and($pivot->exists)->toBeTrue();
});

/**
 * BankStatementLine declared no $table property, so Eloquent's naming
 * convention resolved to `bank_statement_lines` instead of the real
 * migrated table `accounts_bank_statement_lines` (#138 audit, PR 0
 * prerequisite #3).
 *
 * Uses Model::create() directly rather than BankStatement::factory()/
 * BankStatementLine::factory() — neither declares a newFactory() override,
 * a separate pre-existing gap out of scope for this PR. Every column on
 * both tables is nullable, so an empty create() is a valid row.
 */
it('resolves BankStatementLine to its real migrated table and can be queried', function () {
    expect((new BankStatementLine)->getTable())->toBe('accounts_bank_statement_lines');

    // BankStatementLine strictly derives its own company_id from this
    // statement's (#138 review, 2026-07-18) — an explicit company_id is
    // required here, unlike the rest of this file's empty create([]).
    // No acting user yet — BankStatement's write-authorization check
    // needs a system context for this fixture too (#138 review round 2,
    // 2026-07-18).
    [$statement, $line] = TestBootstrapHelper::withSystemContextIfNoUser(function () {
        $statement = BankStatement::create(['company_id' => Company::factory()->create()->id]);

        // BankStatementLine declares no $fillable, so mass assignment is
        // guarded by default — set the attribute directly instead.
        $line = new BankStatementLine;
        $line->statement_id = $statement->id;
        $line->save();

        return [$statement, $line];
    });

    // BankStatementLine now carries HasCompanyScope (#138 audit follow-up)
    // — with no acting user and no active CompanyContext, CompanyScope
    // fails closed (ADR 0007), so this system-context read needs the same
    // helper other no-user fixture reads already use.
    $exists = TestBootstrapHelper::withSystemContextIfNoUser(
        fn () => BankStatementLine::query()->whereKey($line->id)->exists()
    );

    expect($exists)->toBeTrue();
});
