<?php

// apps/aureuserp/tests/Feature/Support/CompanyScopeAuditorTest.php

use App\Support\CompanyScopeAudit\Auditor;
use App\Support\CompanyScopeAudit\ExceptionManifest;
use Illuminate\Database\Eloquent\Model;
use Webkul\Account\Models\Customer as AccountCustomer;
use Webkul\Partner\Models\Partner;
use Webkul\Support\Traits\HasCompanyScope;

// Fixtures below deliberately reuse the already-migrated `accounts_journals`
// table (company_id present, HasCompanyScope-eligible) instead of creating
// throwaway tables — DDL inside a DatabaseTransactions-wrapped test causes
// an implicit MySQL commit that desyncs the transaction (see the
// TestBootstrapHelper note in SeedGoldStandardDatasetCommandTest.php for a
// documented instance of exactly this hazard). Reading an existing table's
// schema is side-effect-free and needs no such workaround.

/** A model that genuinely uses HasCompanyScope against an already-scoped table — its audited status is "scoped". */
class AuditFixtureScopedModel extends Model
{
    use HasCompanyScope;

    protected $table = 'accounts_journals';
}

/** Same table, without the trait — its audited status is "missing_scope". */
class AuditFixtureMissingScopeModel extends Model
{
    protected $table = 'accounts_journals';
}

/** Points at a table that will never exist — its audited status is "table_missing". */
class AuditFixtureGhostModel extends Model
{
    protected $table = 'zzz_audit_fixture_table_never_migrated';
}

function auditorManifest(array $entries): ExceptionManifest
{
    return new ExceptionManifest($entries);
}

it('accepts a valid exception from the real, shipped manifest', function () {
    $auditor = new Auditor;
    $manifest = ExceptionManifest::default();

    $row = $auditor->inspectClass('partners', Partner::class, 'fixture');
    expect($row['status'])->toBe('missing_scope');

    [$classified] = $auditor->classifyRows([$row], $manifest);
    expect($classified['classification'])->toBe('global_party_identity');
    expect($auditor->isRealMissingScope($classified))->toBeFalse();

    $violations = $auditor->validateManifest($manifest);
    $violationsForPartner = array_filter($violations, fn (array $v) => $v['fqcn'] === Partner::class);
    expect($violationsForPartner)->toBeEmpty();
});

it('classifies an expected alias and excludes it from the real-gap count', function () {
    $auditor = new Auditor;
    $manifest = ExceptionManifest::default();

    $row = $auditor->inspectClass('accounts', AccountCustomer::class, 'fixture');
    expect($row['status'])->toBe('missing_scope');

    [$classified] = $auditor->classifyRows([$row], $manifest);
    expect($classified['classification'])->toBe('alias');
    expect($auditor->isRealMissingScope($classified))->toBeFalse();
});

it('flags a stale exception once the model genuinely has HasCompanyScope', function () {
    $auditor = new Auditor;
    $manifest = auditorManifest([
        AuditFixtureScopedModel::class => [
            'table'          => 'accounts_journals',
            'classification' => 'not_tenancy',
            'reason'         => 'fixture: pretends this needs an exception, but it is actually scoped',
            'tracking'       => '#138',
        ],
    ]);

    $row = $auditor->inspectClass('fixture', AuditFixtureScopedModel::class, 'fixture');
    expect($row['status'])->toBe('scoped');

    $violations = $auditor->validateManifest($manifest);
    expect($violations)->toHaveCount(1);
    expect($violations[0]['type'])->toBe('stale_exception');
    expect($violations[0]['fqcn'])->toBe(AuditFixtureScopedModel::class);
});

it('flags a manifest entry whose recorded table does not match the real table', function () {
    $auditor = new Auditor;
    $manifest = auditorManifest([
        AuditFixtureMissingScopeModel::class => [
            'table'          => 'this_table_does_not_match',
            'classification' => 'not_tenancy',
            'reason'         => 'fixture: wrong table on purpose',
            'tracking'       => '#138',
        ],
    ]);

    $violations = $auditor->validateManifest($manifest);
    expect($violations)->toHaveCount(1);
    expect($violations[0]['type'])->toBe('table_mismatch');
    expect($violations[0]['fqcn'])->toBe(AuditFixtureMissingScopeModel::class);
});

it('counts a genuinely missing_scope model with no manifest entry as a real gap', function () {
    $auditor = new Auditor;
    $manifest = auditorManifest([]);

    $row = $auditor->inspectClass('fixture', AuditFixtureMissingScopeModel::class, 'fixture');
    expect($row['status'])->toBe('missing_scope');

    [$classified] = $auditor->classifyRows([$row], $manifest);
    expect($classified['classification'])->toBeNull();
    expect($auditor->isRealMissingScope($classified))->toBeTrue();
});

it('reports table_missing for a model whose table is not in the migrated schema', function () {
    $auditor = new Auditor;

    $row = $auditor->inspectClass('fixture', AuditFixtureGhostModel::class, 'fixture');

    expect($row['status'])->toBe('table_missing');
    expect($row['has_company_id'])->toBeNull();
    expect($row['uses_company_scope'])->toBeNull();
});

it('rejects an unknown classification value in the manifest', function () {
    $auditor = new Auditor;
    $manifest = auditorManifest([
        AuditFixtureMissingScopeModel::class => [
            'table'          => 'accounts_journals',
            'classification' => 'not_a_real_classification',
            'reason'         => 'fixture: invalid classification on purpose',
            'tracking'       => '#138',
        ],
    ]);

    $violations = $auditor->validateManifest($manifest);
    $invalid = array_filter($violations, fn (array $v) => $v['type'] === 'invalid_classification');
    expect($invalid)->toHaveCount(1);
});

it('rejects a manifest entry whose class no longer exists', function () {
    $auditor = new Auditor;
    $manifest = auditorManifest([
        'Webkul\\Nonexistent\\Models\\GhostClass' => [
            'table'          => 'partners_partners',
            'classification' => 'alias',
            'reason'         => 'fixture: dangling entry on purpose',
            'tracking'       => '#138',
        ],
    ]);

    $violations = $auditor->validateManifest($manifest);
    expect($violations)->toHaveCount(1);
    expect($violations[0]['type'])->toBe('class_not_found');
});
