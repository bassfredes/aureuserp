<?php

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
 */
function runErpInstallProcess(string $stdin): Process
{
    $process = new Process(
        [
            PHP_BINARY,
            'artisan',
            'erp:install',
            '--admin-name=Test Admin',
            '--admin-email=admin@erp.localhost',
            '--admin-password=Admin123456',
        ],
        base_path(),
    );

    $process->setInput($stdin);
    $process->setTimeout(120);
    $process->run();

    return $process;
}

it('creates exactly one company and the admin user on a fresh install', function () {
    expect(Company::query()->count())->toBe(1);
    expect(User::where('email', 'admin@erp.localhost')->exists())->toBeTrue();
});

it('wipes and reinstalls when the operator confirms reinstallation', function () {
    // isAlreadyInstalled() reads storage/installed, written by the
    // ensureERPInstalled() call in beforeEach — the confirmation prompts
    // (handleReinstallation()) only trigger when that marker exists.
    $process = runErpInstallProcess("REINSTALL\nyes\n");

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
    expect(Company::query()->count())->toBe(1);
    expect(User::where('email', 'admin@erp.localhost')->exists())->toBeTrue();
});

it('cancels without wiping when the operator declines the first reinstall prompt', function () {
    $before = Company::query()->count();

    $process = runErpInstallProcess("something-else\n");

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
    expect(Company::query()->count())->toBe($before);
});
