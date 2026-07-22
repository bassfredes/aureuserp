<?php

// apps/aureuserp/tests/Feature/Support/CompanyScopeAuditorTest.php

use App\Support\CompanyScopeAudit\Auditor;
use App\Support\CompanyScopeAudit\ExceptionManifest;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Process\Process;
use Webkul\Account\Models\Category as AccountCategory;
use Webkul\Account\Models\Customer as AccountCustomer;
use Webkul\Invoice\Models\Category;
use Webkul\Partner\Models\Partner;
use Webkul\Purchase\Models\Category as PurchaseCategory;
use Webkul\Support\Models\Currency;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\TableViews\Models\TableView;
use Webkul\TableViews\Models\TableViewFavorite;

/**
 * Runs the REAL CLI script (scripts/audit-company-scope.php) as a
 * subprocess against a temp fixture manifest, via
 * COMPANY_SCOPE_MANIFEST_PATH. Exercises the actual orchestration order
 * (inspect -> validate manifest -> exit-or-classify), not just Auditor
 * methods called directly — a unit-level test of validateManifest() alone
 * cannot prove the CLI validates before it classifies (#138, PR 4 review,
 * 2026-07-20).
 *
 * @param  array<class-string, array<string, mixed>>  $manifestEntries
 */
function runAuditScript(array $manifestEntries, array $args = []): Process
{
    $manifestPath = tempnam(sys_get_temp_dir(), 'company-scope-manifest-').'.php';
    file_put_contents($manifestPath, '<?php return '.var_export($manifestEntries, true).';'.PHP_EOL);

    $process = new Process(
        array_merge([PHP_BINARY, base_path('scripts/audit-company-scope.php')], $args),
        base_path(),
        array_merge($_SERVER, $_ENV, ['COMPANY_SCOPE_MANIFEST_PATH' => $manifestPath]),
    );
    $process->run();

    @unlink($manifestPath);

    return $process;
}

// Fixtures below deliberately reuse already-migrated tables (company_id
// present or absent as needed) instead of creating throwaway ones — DDL
// inside a DatabaseTransactions-wrapped test causes an implicit MySQL
// commit that desyncs the transaction (see the TestBootstrapHelper note in
// SeedGoldStandardDatasetCommandTest.php for a documented instance of
// exactly this hazard). Reading an existing table's schema is
// side-effect-free and needs no such workaround.

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

/** Existing table with no company_id column, but this FQCN is deliberately not in the manifest. */
class AuditFixtureUnclassifiedNoCompanyModel extends Model
{
    protected $table = 'accounts_account_tags';
}

/** Fixture pair used to exercise alias-chain cycle detection. */
class AuditFixtureAliasA extends Model
{
    protected $table = 'accounts_journals';
}

class AuditFixtureAliasB extends Model
{
    protected $table = 'accounts_journals';
}

function auditorManifest(array $entries): ExceptionManifest
{
    return new ExceptionManifest($entries);
}

// --- classification / real-gap accounting ---------------------------------

it('accepts a valid exception from the real, shipped manifest', function () {
    $auditor = new Auditor;
    $manifest = ExceptionManifest::default();

    $row = $auditor->inspectClass('partners', Partner::class, 'fixture');
    expect($row['status'])->toBe('missing_scope');

    [$classified] = $auditor->classifyRows([$row], $manifest);
    expect($classified['classification'])->toBe('global_party_identity');
    expect($classified['effective_status'])->toBe('classified_exception');
    expect($auditor->isRealGap($classified))->toBeFalse();

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
    expect($auditor->isRealGap($classified))->toBeFalse();
});

it('resolves a real multi-hop alias chain (purchases -> invoices -> accounts -> products) with no violations', function () {
    $auditor = new Auditor;
    $manifest = ExceptionManifest::default();

    $row = $auditor->inspectClass('purchases', PurchaseCategory::class, 'fixture');
    [$classified] = $auditor->classifyRows([$row], $manifest);
    expect($classified['classification'])->toBe('alias');

    $violations = $auditor->validateManifest($manifest);
    $forChain = array_filter($violations, fn (array $v) => in_array($v['fqcn'], [
        PurchaseCategory::class,
        Category::class,
        AccountCategory::class,
        Webkul\Product\Models\Category::class,
    ], true));
    expect($forChain)->toBeEmpty();
});

it('counts a not_company_scoped model with a valid global_reference exception as classified, not a gap', function () {
    $auditor = new Auditor;
    $manifest = ExceptionManifest::default();

    $row = $auditor->inspectClass('support', Currency::class, 'fixture');
    expect($row['status'])->toBe('not_company_scoped');

    [$classified] = $auditor->classifyRows([$row], $manifest);
    expect($classified['classification'])->toBe('global_reference');
    expect($classified['effective_status'])->toBe('classified_exception');
    expect($auditor->isRealGap($classified))->toBeFalse();
});

it('counts a not_company_scoped model with no manifest entry as a real gap without a company column', function () {
    $auditor = new Auditor;
    $manifest = auditorManifest([]);

    $row = $auditor->inspectClass('fixture', AuditFixtureUnclassifiedNoCompanyModel::class, 'fixture');
    expect($row['status'])->toBe('not_company_scoped');

    [$classified] = $auditor->classifyRows([$row], $manifest);
    expect($classified['classification'])->toBeNull();
    expect($classified['effective_status'])->toBe('real_gap_without_company_column');
    expect($auditor->isRealGap($classified))->toBeTrue();
});

it('counts a genuinely missing_scope model with no manifest entry as a real gap with a company column', function () {
    $auditor = new Auditor;
    $manifest = auditorManifest([]);

    $row = $auditor->inspectClass('fixture', AuditFixtureMissingScopeModel::class, 'fixture');
    expect($row['status'])->toBe('missing_scope');

    [$classified] = $auditor->classifyRows([$row], $manifest);
    expect($classified['classification'])->toBeNull();
    expect($classified['effective_status'])->toBe('real_gap_company_column');
    expect($auditor->isRealGap($classified))->toBeTrue();
});

it('reports table_missing for a model whose table is not in the migrated schema', function () {
    $auditor = new Auditor;

    $row = $auditor->inspectClass('fixture', AuditFixtureGhostModel::class, 'fixture');

    expect($row['status'])->toBe('table_missing');
    expect($row['has_company_id'])->toBeNull();
    expect($row['uses_company_scope'])->toBeNull();
});

// --- manifest hardening -----------------------------------------------------

it('flags a stale exception the moment the model uses HasCompanyScope, regardless of has_company_id', function () {
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
    expect($row['has_company_id'])->toBeTrue();
    expect($row['uses_company_scope'])->toBeTrue();

    $violations = $auditor->validateManifest($manifest);
    $stale = array_filter($violations, fn (array $v) => $v['type'] === 'stale_exception');
    expect($stale)->toHaveCount(1);
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
    $mismatch = array_filter($violations, fn (array $v) => $v['type'] === 'table_mismatch' && $v['fqcn'] === AuditFixtureMissingScopeModel::class);
    expect($mismatch)->toHaveCount(1);
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
            'classification' => 'global_reference',
            'reason'         => 'fixture: dangling entry on purpose',
            'tracking'       => '#138',
        ],
    ]);

    $violations = $auditor->validateManifest($manifest);
    expect($violations)->toHaveCount(1);
    expect($violations[0]['type'])->toBe('class_not_found');
});

it('rejects an entry with an empty required shape field', function () {
    $auditor = new Auditor;
    $manifest = auditorManifest([
        AuditFixtureMissingScopeModel::class => [
            'table'          => 'accounts_journals',
            'classification' => 'not_tenancy',
            'reason'         => '',
            'tracking'       => '#138',
        ],
    ]);

    $violations = $auditor->validateManifest($manifest);
    $shape = array_filter($violations, fn (array $v) => $v['type'] === 'invalid_shape');
    expect($shape)->not->toBeEmpty();
});

it('rejects an entry with a completely removed required key, and only reports invalid_shape', function (string $missingKey) {
    $auditor = new Auditor;

    $entry = [
        'table'          => 'accounts_journals',
        'classification' => 'not_tenancy',
        'reason'         => 'fixture: missing a key entirely on purpose',
        'tracking'       => '#138',
    ];
    unset($entry[$missingKey]);

    $manifest = auditorManifest([
        AuditFixtureMissingScopeModel::class => $entry,
    ]);

    $violations = $auditor->validateManifest($manifest);

    expect($violations)->toHaveCount(1);
    expect($violations[0]['type'])->toBe('invalid_shape');
    expect($violations[0]['message'])->toContain($missingKey);
})->with(['table', 'classification', 'reason', 'tracking']);

it('does not run reflection or alias-chain checks on an entry with an invalid shape', function () {
    // Regression guard: before the fix, an entry missing 'table' still fell
    // through into validateEntryAgainstReflection(), which reads
    // $entry['table'] unconditionally — a PHP warning at best, a confusing
    // second violation at worst.
    $auditor = new Auditor;
    $manifest = auditorManifest([
        AuditFixtureMissingScopeModel::class => [
            'classification' => 'alias',
            'reason'         => 'fixture: missing table AND alias_of at once',
            'tracking'       => '#138',
        ],
    ]);

    $violations = $auditor->validateManifest($manifest);

    foreach ($violations as $violation) {
        expect($violation['type'])->toBe('invalid_shape');
    }
});

// --- end-to-end: the real CLI orchestration order, not just Auditor calls --

it('the real CLI script exits 2 on a missing "table" key and never warns, before classifying any row', function () {
    $process = runAuditScript([
        Partner::class => [
            'classification' => 'global_party_identity',
            'reason'         => 'fixture: missing table key entirely, exercised through the real CLI',
            'tracking'       => '#138',
        ],
    ], ['--plugins=partners', '--format=json']);

    expect($process->getExitCode())->toBe(2);

    $combined = $process->getOutput().$process->getErrorOutput();
    expect($combined)->not->toContain('Undefined array key');
    expect($combined)->not->toContain('Warning');

    $payload = json_decode($process->getOutput(), true);
    expect($payload)->not->toBeNull();
    expect($payload['rows'])->toBeNull();
    expect($payload['summary'])->toBeNull();

    $shapeViolation = array_filter(
        $payload['manifest_violations'],
        fn (array $v) => $v['type'] === 'invalid_shape' && $v['fqcn'] === Partner::class,
    );
    expect($shapeViolation)->not->toBeEmpty();
});

it('the real CLI script exits 2 on a missing "classification" key and never warns, before classifying any row', function () {
    $process = runAuditScript([
        Partner::class => [
            'table'    => 'partners_partners',
            'reason'   => 'fixture: missing classification key entirely, exercised through the real CLI',
            'tracking' => '#138',
        ],
    ], ['--plugins=partners', '--format=json']);

    expect($process->getExitCode())->toBe(2);

    $combined = $process->getOutput().$process->getErrorOutput();
    expect($combined)->not->toContain('Undefined array key');
    expect($combined)->not->toContain('Warning');

    $payload = json_decode($process->getOutput(), true);
    expect($payload)->not->toBeNull();
    expect($payload['rows'])->toBeNull();
    expect($payload['summary'])->toBeNull();

    $shapeViolation = array_filter(
        $payload['manifest_violations'],
        fn (array $v) => $v['type'] === 'invalid_shape' && $v['fqcn'] === Partner::class,
    );
    expect($shapeViolation)->not->toBeEmpty();
});

it('the real CLI script exits 0 for the actual shipped manifest with no violations', function () {
    // Sanity check that runAuditScript()/the real manifest path work
    // end-to-end when nothing is broken — protects against the two tests
    // above passing for the wrong reason (e.g. the script crashing before
    // even reaching manifest validation).
    $process = new Process(
        [PHP_BINARY, base_path('scripts/audit-company-scope.php'), '--plugins=partners', '--format=json'],
        base_path(),
        array_merge($_SERVER, $_ENV),
    );
    $process->run();

    expect($process->getExitCode())->toBe(0);

    $payload = json_decode($process->getOutput(), true);
    expect($payload['manifest_violations'])->toBe([]);
    expect($payload['summary']['manifest_violations'])->toBe(0);
});

it('rejects an alias classification with no alias_of target', function () {
    $auditor = new Auditor;
    $manifest = auditorManifest([
        AuditFixtureMissingScopeModel::class => [
            'table'          => 'accounts_journals',
            'classification' => 'alias',
            'reason'         => 'fixture: alias without alias_of on purpose',
            'tracking'       => '#138',
        ],
    ]);

    $violations = $auditor->validateManifest($manifest);
    $shape = array_filter($violations, fn (array $v) => $v['type'] === 'invalid_shape' && str_contains($v['message'], 'alias_of'));
    expect($shape)->not->toBeEmpty();
});

it('rejects an alias chain pointing at a target with no manifest entry and no autoloadable class', function () {
    $auditor = new Auditor;
    $manifest = auditorManifest([
        AuditFixtureAliasA::class => [
            'table'          => 'accounts_journals',
            'classification' => 'alias',
            'alias_of'       => 'Totally\\Nonexistent\\Namespace\\ClassX',
            'reason'         => 'fixture: broken chain on purpose',
            'tracking'       => '#138',
        ],
    ]);

    $violations = $auditor->validateManifest($manifest);
    $broken = array_filter($violations, fn (array $v) => $v['type'] === 'alias_chain_broken' && $v['fqcn'] === AuditFixtureAliasA::class);
    expect($broken)->not->toBeEmpty();
});

it('rejects an alias chain pointing at a real, autoloadable, same-table class that just has no manifest entry of its own', function () {
    // Distinct from the "totally nonexistent class" case above: here the
    // alias_of target genuinely exists, is a real Eloquent model, and
    // shares the same table — but it was never registered in the
    // manifest. A chain must terminate in something THIS manifest has
    // actually classified, not in an arbitrary real class that could
    // itself be an unclassified gap.
    $auditor = new Auditor;
    $manifest = auditorManifest([
        AuditFixtureAliasA::class => [
            'table'          => 'accounts_journals',
            'classification' => 'alias',
            'alias_of'       => AuditFixtureMissingScopeModel::class,
            'reason'         => 'fixture: real target, but unregistered, on purpose',
            'tracking'       => '#138',
        ],
    ]);

    expect(class_exists(AuditFixtureMissingScopeModel::class))->toBeTrue();
    expect($manifest->has(AuditFixtureMissingScopeModel::class))->toBeFalse();

    $violations = $auditor->validateManifest($manifest);
    $broken = array_filter($violations, fn (array $v) => $v['type'] === 'alias_chain_broken' && $v['fqcn'] === AuditFixtureAliasA::class);
    expect($broken)->not->toBeEmpty();
});

it('detects an alias chain cycle between two manifest entries', function () {
    $auditor = new Auditor;
    $manifest = auditorManifest([
        AuditFixtureAliasA::class => [
            'table'          => 'accounts_journals',
            'classification' => 'alias',
            'alias_of'       => AuditFixtureAliasB::class,
            'reason'         => 'fixture: cycle on purpose',
            'tracking'       => '#138',
        ],
        AuditFixtureAliasB::class => [
            'table'          => 'accounts_journals',
            'classification' => 'alias',
            'alias_of'       => AuditFixtureAliasA::class,
            'reason'         => 'fixture: cycle on purpose',
            'tracking'       => '#138',
        ],
    ]);

    $violations = $auditor->validateManifest($manifest);
    $cycle = array_filter($violations, fn (array $v) => $v['type'] === 'alias_chain_cycle');
    expect($cycle)->not->toBeEmpty();
});

it('does not require a manifest entry\'s table to physically exist — that is inspectClass()\'s job, scoped to the current run', function () {
    // Simulates: auditing plugin A while the manifest carries a valid
    // entry for a model in plugin B, whose table isn't installed in
    // this particular run's schema. Manifest validation is static
    // (shape/class/table-name/classification/alias-chain via reflection)
    // and must not depend on which plugins were actually inspected, nor
    // on every manifest entry's table physically existing right now.
    $auditor = new Auditor;
    $manifest = auditorManifest([
        AuditFixtureGhostModel::class => [
            'table'          => 'zzz_audit_fixture_table_never_migrated',
            'classification' => 'not_tenancy',
            'reason'         => 'fixture: table genuinely not migrated in this run, entry is still statically valid',
            'tracking'       => '#138',
        ],
    ]);

    $violations = $auditor->validateManifest($manifest);
    expect($violations)->toBeEmpty();

    // A narrow, single-plugin run doesn't even see this class — its own
    // table_missing detection stays scoped to what it actually inspected.
    $rows = $auditor->inspectPlugins(['partners']);
    expect(array_filter($rows, fn (array $r) => $r['class'] === AuditFixtureGhostModel::class))->toBeEmpty();
});

it('classifies TableView/TableViewFavorite now that the ownership resolver closes the IDOR (#138 PR4 ola4A)', function () {
    // The approved contract (#138 review, 2026-07-19) required a real
    // server-side ownership fix in EditViewAction/deleteTableViewAction/
    // replaceTableViewAction before either model could be classified.
    // TableView::resolveOwnedTableViewOrFail() / TableViewFavorite::
    // toggleForOwnViewOrFail() now close that gap (see
    // plugins/webkul/table-views/tests/Feature/TableViewOwnershipTest.php),
    // so the manifest entry is no longer silencing an unfixed bug.
    $auditor = new Auditor;
    $manifest = ExceptionManifest::default();

    expect($manifest->has(TableView::class))->toBeTrue();
    expect($manifest->has(TableViewFavorite::class))->toBeTrue();
    expect($manifest->get(TableView::class)['classification'])->toBe('not_tenancy');
    expect($manifest->get(TableViewFavorite::class)['classification'])->toBe('not_tenancy');

    $tableViewRow = $auditor->inspectClass('table-views', TableView::class, 'fixture');
    [$classified] = $auditor->classifyRows([$tableViewRow], $manifest);
    expect($classified['effective_status'])->toBe('classified_exception');
    expect($auditor->isRealGap($classified))->toBeFalse();

    $favoriteRow = $auditor->inspectClass('table-views', TableViewFavorite::class, 'fixture');
    [$classifiedFavorite] = $auditor->classifyRows([$favoriteRow], $manifest);
    expect($classifiedFavorite['effective_status'])->toBe('classified_exception');
    expect($auditor->isRealGap($classifiedFavorite))->toBeFalse();
});

// --- table output -------------------------------------------------------------

it('shows a real gap without a company column in the default table output', function () {
    $auditor = new Auditor;
    $manifest = auditorManifest([]);

    $row = $auditor->inspectClass('fixture', AuditFixtureUnclassifiedNoCompanyModel::class, 'fixture');
    [$classified] = $auditor->classifyRows([$row], $manifest);

    expect($classified['has_company_id'])->toBeFalse();
    expect($auditor->shouldDisplayInTable($classified))->toBeTrue();
});

it('hides a classified exception with no company column from the default table output', function () {
    $auditor = new Auditor;
    $manifest = ExceptionManifest::default();

    $row = $auditor->inspectClass('support', Currency::class, 'fixture');
    [$classified] = $auditor->classifyRows([$row], $manifest);

    expect($classified['has_company_id'])->toBeFalse();
    expect($classified['effective_status'])->toBe('classified_exception');
    expect($auditor->shouldDisplayInTable($classified))->toBeFalse();
});

it('always shows table_missing rows in the default table output', function () {
    $auditor = new Auditor;
    $manifest = auditorManifest([]);

    $row = $auditor->inspectClass('fixture', AuditFixtureGhostModel::class, 'fixture');
    [$classified] = $auditor->classifyRows([$row], $manifest);

    expect($auditor->shouldDisplayInTable($classified))->toBeTrue();
});

// --- plugin discovery --------------------------------------------------------

it('discovers every plugin with a src/Models directory, deterministically sorted', function () {
    $auditor = new Auditor;

    $first = $auditor->discoverPlugins();
    $second = $auditor->discoverPlugins();

    expect($first)->toBe($second);
    expect($first)->toBe(collect($first)->sort(SORT_STRING)->values()->all());
    expect($first)->toContain('accounts', 'partners', 'products', 'employees');
    expect($first)->not->toContain('barcode', 'full-calendar');
});

it('scopes inspection to only the requested plugins', function () {
    $auditor = new Auditor;

    $rows = $auditor->inspectPlugins(['partners']);

    expect($rows)->not->toBeEmpty();
    foreach ($rows as $row) {
        expect($row['plugin'])->toBe('partners');
    }
});

it('throws for a nonexistent explicitly requested plugin', function () {
    $auditor = new Auditor;

    expect(fn () => $auditor->inspectPlugins(['totally-not-a-plugin']))
        ->toThrow(RuntimeException::class);
});
