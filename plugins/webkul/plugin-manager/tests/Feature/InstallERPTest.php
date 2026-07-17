<?php

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensureERPInstalled();
});

/**
 * The reinstall path runs a real migrate:fresh internally
 * (InstallERP::wipeDatabase() -> Artisan::call('migrate:fresh')) — running
 * that a second time through Pest's own DatabaseTransactions-wrapped
 * connection desyncs migration tracking (same root cause documented in
 * tests/Feature/Console/SeedGoldStandardDatasetCommandTest.php), so the
 * reinstall itself runs as a genuine separate OS process on its own
 * connection; only the verification queries afterward use the normal
 * (transactional) test connection.
 *
 * TestBootstrapHelper::assertSafeToRunDestructiveBootstrap() is asserted
 * here too — the subprocess bypasses that helper entirely (it calls
 * erp:install directly), so this is the one place standing between a
 * misconfigured environment and a real migrate:fresh against the wrong
 * database.
 *
 * DatabaseTransactions wraps this whole test in an open transaction on the
 * SAME MySQL connection the subprocess's migrate:fresh needs — DROP TABLE
 * requires an exclusive metadata lock, which MySQL will not grant while
 * this process still holds a shared one from that open transaction (even
 * one that never wrote anything). Left alone, the subprocess deadlocks
 * against its own parent. Committing before the subprocess runs releases
 * that lock; reopening a transaction after gives the DatabaseTransactions
 * trait's own tearDown something to roll back, same as if this had never
 * happened.
 */
function runErpInstallProcess(string $adminEmail, string $stdin): Process
{
    TestBootstrapHelper::assertSafeToRunDestructiveBootstrap();

    $process = new Process(
        [
            PHP_BINARY,
            'artisan',
            'erp:install',
            '--admin-name=Test Admin',
            "--admin-email={$adminEmail}",
            '--admin-password=Admin123456',
        ],
        base_path(),
    );

    $process->setInput($stdin);
    $process->setTimeout(300);

    DB::commit();

    try {
        $process->run();
    } finally {
        DB::beginTransaction();
    }

    return $process;
}

it('creates exactly one company and the admin user on a fresh install', function () {
    expect(Company::query()->count())->toBe(1);
    expect(User::where('email', 'admin@erp.localhost')->exists())->toBeTrue();
});

it('wipes and reinstalls when the operator confirms reinstallation', function () {
    expect(User::where('email', 'admin@erp.localhost')->exists())->toBeTrue();

    // A distinct, per-run email is the actual discriminant: comparing row
    // counts alone would also pass for a no-op that never wiped anything
    // (both before and after are "one company, one admin"). A real wipe
    // must both erase the pre-existing admin@erp.localhost and create
    // this run's own new admin from scratch.
    $newAdminEmail = 'reinstall-confirmed-'.uniqid().'@test.local';

    $process = runErpInstallProcess($newAdminEmail, "REINSTALL\nyes\n");

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
    expect(Company::query()->count())->toBe(1);
    expect(User::where('email', 'admin@erp.localhost')->exists())->toBeFalse();
    expect(User::where('email', $newAdminEmail)->exists())->toBeTrue();
});

it('cancels without wiping when the operator declines the first reinstall prompt', function () {
    // migrate:fresh is not something DatabaseTransactions can roll back
    // between tests (it runs as a real subprocess, see
    // runErpInstallProcess()) — whichever admin exists here may be the
    // original admin@erp.localhost, or the previous test's own reinstall
    // email, depending on execution order. The invariant under test is
    // "declining changes nothing", not any one specific address.
    $existingAdminEmail = User::query()->value('email');
    expect($existingAdminEmail)->not->toBeNull();

    // Same reasoning in reverse: a completed reinstall that happened to
    // recreate exactly one company again would also pass a bare count
    // comparison. Declining must leave the pre-existing admin untouched
    // and never create this run's new admin at all.
    $declinedAdminEmail = 'reinstall-declined-'.uniqid().'@test.local';

    $process = runErpInstallProcess($declinedAdminEmail, "something-else\n");

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
    expect(Company::query()->count())->toBe(1);
    expect(User::where('email', $existingAdminEmail)->exists())->toBeTrue();
    expect(User::where('email', $declinedAdminEmail)->exists())->toBeFalse();
});
