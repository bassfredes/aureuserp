<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Webkul\Employee\Models\Employee;
use Webkul\Partner\Models\Partner;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('employees');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

function backfillEmployeeIn(int $companyId): Employee
{
    return CompanyContext::runForAllCompanies(
        reason: 'fixture',
        caller: __FILE__,
        callback: fn () => Employee::factory()->create(['company_id' => $companyId]),
    );
}

function backfillCompany(): Company
{
    return CompanyContext::runForAllCompanies(
        reason: 'fixture',
        caller: __FILE__,
        callback: fn () => Company::factory()->create(),
    );
}

function backfillPartner(): Partner
{
    return CompanyContext::runForAllCompanies(
        reason: 'fixture',
        caller: __FILE__,
        callback: fn () => Partner::factory()->create(),
    );
}

/**
 * Inserted directly via the DB facade, bypassing Eloquent entirely — this
 * is the only way to produce a "legacy, not-yet-backfilled" row at all,
 * since Message/Attachment/Follower's own saving hooks (ResolvesChatterCompany)
 * now always derive company_id on every save through the model layer. Same
 * simulated-legacy-data technique Sale\Console\Commands\BackfillTagCompanyId's
 * own test suite precedent uses for sales_tags.
 */
function insertLegacyMessage(string $type, int $id): int
{
    return DB::table('chatter_messages')->insertGetId([
        'company_id'       => null,
        'messageable_type' => $type,
        'messageable_id'   => $id,
        'type'             => 'comment',
        'body'             => 'legacy',
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);
}

it('backfills a chatter_messages row whose owner is a HasCompanyScope model (Employee)', function () {
    $company = backfillCompany();
    $employee = backfillEmployeeIn($company->id);

    $id = insertLegacyMessage($employee->getMorphClass(), $employee->id);

    Artisan::call('chatter:backfill-company');

    expect(DB::table('chatter_messages')->where('id', $id)->value('company_id'))->toBe($company->id);
});

it('backfills a chatter_messages row whose owner IS a Company', function () {
    $company = backfillCompany();

    $id = insertLegacyMessage($company->getMorphClass(), $company->id);

    Artisan::call('chatter:backfill-company');

    expect(DB::table('chatter_messages')->where('id', $id)->value('company_id'))->toBe($company->id);
});

it('reports a row whose owner has no company scope of its own (Partner) as requiring manual resolution and leaves it untouched', function () {
    $partner = backfillPartner();

    $id = insertLegacyMessage($partner->getMorphClass(), $partner->id);

    $exitCode = Artisan::call('chatter:backfill-company');

    expect($exitCode)->toBe(0)
        ->and(DB::table('chatter_messages')->where('id', $id)->value('company_id'))->toBeNull();
    expect(Artisan::output())->toContain('requires manual resolution');
});

it('reports a row whose owner no longer exists as requiring manual resolution and leaves it untouched', function () {
    $company = backfillCompany();
    $employee = backfillEmployeeIn($company->id);
    $ghostId = $employee->id + 999999;

    $id = insertLegacyMessage($employee->getMorphClass(), $ghostId);

    Artisan::call('chatter:backfill-company');

    expect(DB::table('chatter_messages')->where('id', $id)->value('company_id'))->toBeNull();
});

it('writes nothing in --dry-run mode even for an otherwise-resolvable row', function () {
    $company = backfillCompany();
    $employee = backfillEmployeeIn($company->id);

    $id = insertLegacyMessage($employee->getMorphClass(), $employee->id);

    Artisan::call('chatter:backfill-company', ['--dry-run' => true]);

    expect(DB::table('chatter_messages')->where('id', $id)->value('company_id'))->toBeNull();
});

it('reports a row whose non-null company_id does not match the owner-derived value, and leaves it untouched', function () {
    $ownerCompany = backfillCompany();
    $wrongCompany = backfillCompany();
    $employee = backfillEmployeeIn($ownerCompany->id);

    // Simulates a legacy row written by the pre-fix caller-derived logic:
    // company_id came from the acting user's default company, not the
    // owner (Employee) being commented on, so it disagrees with what
    // ResolvesChatterCompany would derive from the owner today.
    $id = DB::table('chatter_messages')->insertGetId([
        'company_id'       => $wrongCompany->id,
        'messageable_type' => $employee->getMorphClass(),
        'messageable_id'   => $employee->id,
        'type'             => 'comment',
        'body'             => 'legacy mislabeled',
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    $exitCode = Artisan::call('chatter:backfill-company');

    expect($exitCode)->toBe(0)
        ->and(DB::table('chatter_messages')->where('id', $id)->value('company_id'))->toBe($wrongCompany->id);
    expect(Artisan::output())
        ->toContain('mismatched company_id')
        ->toContain((string) $wrongCompany->id)
        ->toContain((string) $ownerCompany->id);
});

it('reports the same non-null company_id mismatch in --dry-run mode without writing anything', function () {
    $ownerCompany = backfillCompany();
    $wrongCompany = backfillCompany();
    $employee = backfillEmployeeIn($ownerCompany->id);

    $id = DB::table('chatter_messages')->insertGetId([
        'company_id'       => $wrongCompany->id,
        'messageable_type' => $employee->getMorphClass(),
        'messageable_id'   => $employee->id,
        'type'             => 'comment',
        'body'             => 'legacy mislabeled',
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    Artisan::call('chatter:backfill-company', ['--dry-run' => true]);

    expect(DB::table('chatter_messages')->where('id', $id)->value('company_id'))->toBe($wrongCompany->id);
    expect(Artisan::output())->toContain('mismatched company_id');
});

it('backfills chatter_attachments and chatter_followers rows the same way as chatter_messages', function () {
    $company = backfillCompany();
    $employee = backfillEmployeeIn($company->id);
    $partner = backfillPartner();

    $attachmentId = DB::table('chatter_attachments')->insertGetId([
        'company_id'         => null,
        'messageable_type'   => $employee->getMorphClass(),
        'messageable_id'     => $employee->id,
        'name'               => 'file.pdf',
        'original_file_name' => 'file.pdf',
        'file_path'          => 'attachments/file.pdf',
        'mime_type'          => 'application/pdf',
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    $followerId = DB::table('chatter_followers')->insertGetId([
        'company_id'      => null,
        'followable_type' => $employee->getMorphClass(),
        'followable_id'   => $employee->id,
        'partner_id'      => $partner->id,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    Artisan::call('chatter:backfill-company');

    expect(DB::table('chatter_attachments')->where('id', $attachmentId)->value('company_id'))->toBe($company->id)
        ->and(DB::table('chatter_followers')->where('id', $followerId)->value('company_id'))->toBe($company->id);
});
