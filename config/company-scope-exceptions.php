<?php

declare(strict_types=1);
use Webkul\Account\Models\Account;
use Webkul\Account\Models\AccountAccountTag;
use Webkul\Account\Models\AccountJournal;
use Webkul\Account\Models\AccountPaymentRegisterMoveLine;
use Webkul\Account\Models\AccountTax;
use Webkul\Account\Models\CashRounding;
use Webkul\Account\Models\FullReconcile;
use Webkul\Account\Models\Incoterm;
use Webkul\Account\Models\JournalAccount;
use Webkul\Account\Models\PaymentDueTerm;
use Webkul\Account\Models\PaymentMethod;
use Webkul\Account\Models\PaymentMethodLine;
use Webkul\Account\Models\ProductSupplierTaxes;
use Webkul\Account\Models\ProductTaxes;
use Webkul\Account\Models\TaxTaxes;
use Webkul\Accounting\Models\Customer;
use Webkul\Accounting\Models\Vendor;
use Webkul\Blog\Models\Category;
use Webkul\Blog\Models\Post;
use Webkul\Blog\Models\Tag;
use Webkul\Employee\Models\DepartureReason;
use Webkul\Employee\Models\EmployeeCategory;
use Webkul\Employee\Models\EmployeeResumeLineType;
use Webkul\Employee\Models\EmploymentType;
use Webkul\Employee\Models\Skill;
use Webkul\Employee\Models\SkillLevel;
use Webkul\Employee\Models\SkillType;
use Webkul\Field\Models\Field;
use Webkul\Inventory\Models\PackageDestination;
use Webkul\Inventory\Models\ProcurementGroup;
use Webkul\Inventory\Models\ProductQuantityRelocation;
use Webkul\Inventory\Models\StorageCategoryCapacity;
use Webkul\Maintenance\Models\Stage;
use Webkul\Manufacturing\Models\Operation;
use Webkul\Manufacturing\Models\WorkCenterCapacity;
use Webkul\Manufacturing\Models\WorkCenterLossType;
use Webkul\Manufacturing\Models\WorkCenterProductivityLoss;
use Webkul\Manufacturing\Models\WorkCenterTag;
use Webkul\Manufacturing\Models\WorkOrder;
use Webkul\Partner\Models\Address;
use Webkul\Partner\Models\Industry;
use Webkul\Partner\Models\Partner;
use Webkul\Partner\Models\Title;
use Webkul\PluginManager\Models\Plugin;
use Webkul\Product\Models\Attribute;
use Webkul\Product\Models\AttributeOption;
use Webkul\Product\Models\ProductAttribute;
use Webkul\Product\Models\ProductAttributeValue;
use Webkul\Product\Models\ProductCombination;
use Webkul\Project\Models\Milestone;
use Webkul\Purchase\Models\OrderGroup;
use Webkul\Recruitment\Models\ApplicantCategory;
use Webkul\Recruitment\Models\Degree;
use Webkul\Recruitment\Models\RefuseReason;
use Webkul\Sale\Models\OrderLine;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\Team;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ActivityTypeSuggestion;
use Webkul\Support\Models\Bank;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Country;
use Webkul\Support\Models\Currency;
use Webkul\Support\Models\EmailLog;
use Webkul\Support\Models\EmailTemplate;
use Webkul\Support\Models\State;
use Webkul\Support\Models\UOM;
use Webkul\Support\Models\UOMCategory;
use Webkul\Support\Models\UTMMedium;
use Webkul\Support\Models\UTMSource;
use Webkul\Support\Models\UtmStage;
use Webkul\TableViews\Models\TableView;
use Webkul\TableViews\Models\TableViewFavorite;
use Webkul\Website\Models\Page;

/**
 * Formally reviewed exceptions to the company-scope auditor
 * (scripts/audit-company-scope.php). Every entry documents an
 * already-authorized decision (#138) for a model that intentionally has no
 * per-row CompanyScope enforcement.
 *
 * This is the ONLY sanctioned way to silence a `missing_scope`/
 * `not_company_scoped` finding — the auditor does not accept namespace,
 * regex, or short-name exclusions (#138 review, 2026-07-19: those are too
 * easy to widen by accident and would hide a genuine future regression on
 * an unrelated class).
 *
 * Every entry is validated on every run, regardless of --plugins scope:
 *   - `table`/`classification`/`reason`/`tracking` must be non-empty strings;
 *   - the class must exist and be autoloadable;
 *   - `table` must match the model's actual table right now;
 *   - `classification` must be one of App\Support\CompanyScopeAudit\
 *     ExceptionManifest::CLASSIFICATIONS;
 *   - if the model has since gained real HasCompanyScope enforcement, the
 *     entry is "stale" and must be removed, not left in place;
 *   - `classification: alias` entries MUST carry `alias_of`, pointing at
 *     the class this one delegates its identity to. The target must exist,
 *     must actually be an ancestor of the alias (is_subclass_of), must
 *     share the same table, and the alias_of chain must terminate in a
 *     non-alias classification with no cycles.
 *
 * Classification meanings used in this file:
 *   - global_party_identity: Partner/Customer/Vendor — the canonical party
 *     identity, intentionally visible across every company it transacts
 *     with. Isolation is enforced at financial write paths, not here.
 *   - alias: zero-added-schema subclass of another entry in this manifest,
 *     same table, no independent identity.
 *   - global_reference: shared master/reference data with real business
 *     meaning across every company (countries, currencies, banks, UOM,
 *     incoterms, tags/taxonomies, product attributes/categories, UTM
 *     taxonomy, email templates, custom-field definitions). Genuinely has
 *     no company dimension by design.
 *   - not_tenancy: platform/infrastructure constructs that are not business
 *     data at all (RBAC catalog, plugin registry, system logs, single-site
 *     content, user-owned UI state, structurally-company-agnostic pivots).
 *   - parent_scoped: derives its effective isolation from a parent/pivot
 *     that is ALREADY HasCompanyScope-enforced or pivot-validated, with
 *     real, cited code — never used just because "nothing queries it
 *     directly" without a concrete mechanism.
 *   - root_company_entity: the Company model itself — cannot scope itself.
 *   - multi_company_membership: User — company assignment lives in
 *     default_company_id + the user_allowed_companies pivot, read by
 *     CompanyScope::allowedCompanyIds() itself.
 *
 * Models that are real, unresolved gaps (including ones with an approved
 * future contract not yet implemented — Leave/LeaveAllocation, Invitation,
 * BankAccount, Maintenance, ActivityPlan/ActivityType/Calendar family,
 * CurrencyRate, Chatter, UtmCampaign, and every plugin-specific child of a
 * still-unscoped parent) are deliberately NOT in this file — declaring a
 * classification without real enforcement code would defeat the point of
 * the auditor. See docs/security/company-scope-pr4-inventory.md.
 *
 * @return array<class-string, array{table: string, classification: string, reason: string, tracking: string, alias_of?: class-string}>
 */
return [

    // ---------------------------------------------------------------
    // global_party_identity — Partner/Customer/Vendor/Address family
    // ---------------------------------------------------------------

    Partner::class => [
        'table'          => 'partners_partners',
        'classification' => 'global_party_identity',
        'reason'         => 'Canonical party identity (Odoo res.partner equivalent) — a partner/customer/vendor is visible and referenceable across every company it transacts with by design. Isolation for financial write paths that touch it (Move, PaymentRegister, MoveReversal, ...) is enforced at those write paths, not on Partner itself.',
        'tracking'       => '#138',
    ],
    Address::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'alias_of'       => Partner::class,
        'reason'         => 'Same table/class hierarchy as Partner (extends Partner directly in the same namespace), distinguished only by an account_type=\'address\' relation-level scope — not a separate schema. Inherits global_party_identity.',
        'tracking'       => '#138',
    ],
    Webkul\Contact\Models\Partner::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'alias_of'       => Partner::class,
        'reason'         => 'Zero-logic UI-binding subclass of Webkul\\Partner\\Models\\Partner (contacts is the nav-registered layer, partners owns the schema). Same table, same row identity.',
        'tracking'       => '#138',
    ],
    Webkul\Contact\Models\Address::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'alias_of'       => Webkul\Contact\Models\Partner::class,
        'reason'         => 'Zero-logic UI-binding subclass of Webkul\\Contact\\Models\\Partner (same namespace \'extends Partner\'). Same table/identity as Partner.',
        'tracking'       => '#138',
    ],
    Webkul\Accounting\Models\Partner::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'alias_of'       => Webkul\Account\Models\Partner::class,
        'reason'         => 'Extends Webkul\\Account\\Models\\Partner (accounting reuses the accounts-domain alias chain), ultimately the same table/identity.',
        'tracking'       => '#138',
    ],
    Customer::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'alias_of'       => Webkul\Account\Models\Customer::class,
        'reason'         => 'Extends Webkul\\Account\\Models\\Customer (same table, filtered by account_type at the relation level, not a distinct schema). Inherits global_party_identity.',
        'tracking'       => '#138',
    ],
    Vendor::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'alias_of'       => Webkul\Account\Models\Vendor::class,
        'reason'         => 'Extends Webkul\\Account\\Models\\Vendor (same table). Inherits global_party_identity.',
        'tracking'       => '#138',
    ],
    Webkul\Account\Models\Partner::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'alias_of'       => Partner::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Partner\\Models\\Partner used by the accounts (Journal/Move/MoveLine/...) domain, already reviewed in PR #17. Same table/identity.',
        'tracking'       => '#138',
    ],
    Webkul\Account\Models\Customer::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'alias_of'       => Webkul\Account\Models\Partner::class,
        'reason'         => 'Extends Webkul\\Account\\Models\\Partner directly (same plugin, account_type-filtered subclass, not a distinct schema). Inherits global_party_identity.',
        'tracking'       => '#138',
    ],
    Webkul\Account\Models\Vendor::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'alias_of'       => Webkul\Account\Models\Partner::class,
        'reason'         => 'Extends Webkul\\Account\\Models\\Partner directly (same plugin, account_type-filtered subclass, not a distinct schema). Inherits global_party_identity.',
        'tracking'       => '#138',
    ],
    Webkul\Invoice\Models\Partner::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'alias_of'       => Webkul\Account\Models\Partner::class,
        'reason'         => 'Extends Webkul\\Account\\Models\\Partner (invoices reuses the accounts-domain alias chain, already reviewed in PR #17), ultimately the same table/identity.',
        'tracking'       => '#138',
    ],
    Webkul\Invoice\Models\Customer::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'alias_of'       => Webkul\Account\Models\Customer::class,
        'reason'         => 'Extends Webkul\\Account\\Models\\Customer (same table). Inherits global_party_identity.',
        'tracking'       => '#138',
    ],
    Webkul\Invoice\Models\Vendor::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'alias_of'       => Webkul\Account\Models\Vendor::class,
        'reason'         => 'Extends Webkul\\Account\\Models\\Vendor (same table). Inherits global_party_identity.',
        'tracking'       => '#138',
    ],
    Webkul\Purchase\Models\Partner::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'alias_of'       => Webkul\Account\Models\Partner::class,
        'reason'         => 'Extends Webkul\\Account\\Models\\Partner (purchases reuses the accounts-domain alias chain), ultimately the same table/identity.',
        'tracking'       => '#138',
    ],
    Webkul\Sale\Models\Partner::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'alias_of'       => Webkul\Invoice\Models\Partner::class,
        'reason'         => 'Extends Webkul\\Invoice\\Models\\Partner (sales reuses the invoices-domain alias chain), ultimately the same table/identity.',
        'tracking'       => '#138',
    ],
    Webkul\Website\Models\Partner::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'alias_of'       => Partner::class,
        'reason'         => 'Customer-portal-login subtype of Partner (adds password/email_verified_at for self-service login) — the documented ADR 0007 portal exception, not a separate tenant identity.',
        'tracking'       => '#138 / ADR 0007',
    ],

    // ---------------------------------------------------------------
    // root_company_entity / multi_company_membership — security core
    // ---------------------------------------------------------------

    Company::class => [
        'table'          => 'companies',
        'classification' => 'root_company_entity',
        'reason'         => 'The tenant entity itself — cannot scope itself. The `company_id` column on this table is a string external business identifier (tax/ERP code), not a self-referencing tenant FK; the auditor\'s naive hasColumn(\'company_id\') check flags it as a false-positive missing_scope. Access is instead gated by Filament-Shield permissions (*_support_company) on Support\\Policies\\CompanyPolicy, and InstallERP.php uses Company::sole() to fail loud rather than guess (#138 review round, 2026-07-19).',
        'tracking'       => '#138',
    ],
    Webkul\Security\Models\Company::class => [
        'table'          => 'companies',
        'classification' => 'alias',
        'alias_of'       => Company::class,
        'reason'         => 'One-line empty subclass (`class Company extends Webkul\\Support\\Models\\Company {}`) — dead code with zero references outside its own orphaned CompanyPolicy (Laravel\'s policy auto-discovery binds Support\\Policies\\CompanyPolicy to the canonical Support\\Models\\Company instead, per CompanyResource::$model). Kept classified rather than deleted in this checkpoint since deleting it is a code change, not an audit task.',
        'tracking'       => '#138',
    ],
    User::class => [
        'table'          => 'users',
        'classification' => 'multi_company_membership',
        'reason'         => 'The membership root CompanyScope::allowedCompanyIds() itself reads: default_company_id (nullable FK) + the user_allowed_companies pivot. A user can legitimately belong to multiple companies — there is no single company_id to scope by design.',
        'tracking'       => '#138',
    ],

    // ---------------------------------------------------------------
    // not_tenancy — platform/infrastructure, not business data
    // ---------------------------------------------------------------

    Permission::class => [
        'table'          => 'permissions',
        'classification' => 'not_tenancy',
        'reason'         => 'Spatie RBAC capability catalog — permission NAMES are system-wide capability definitions, not tenant data. Per-company enforcement happens on the business rows via CompanyScope, not on which permissions exist (config/permission.php: teams=false, no team_foreign_key).',
        'tracking'       => '#138',
    ],
    Role::class => [
        'table'          => 'roles',
        'classification' => 'not_tenancy',
        'reason'         => 'Spatie RBAC role catalog, same reasoning as Permission — system-wide, not tenant data.',
        'tracking'       => '#138',
    ],
    Team::class => [
        'table'          => 'teams',
        'classification' => 'not_tenancy',
        'reason'         => 'Cross-company grouping construct feeding the opt-in HasPermissionScope::scopeApplyPermissionScope() (resource_permission=\'team\'), which ANDs with (narrows, never replaces) a model\'s own CompanyScope filter — a team spanning companies cannot itself leak cross-company rows, it can only narrow visibility within companies the user already has access to.',
        'tracking'       => '#138',
    ],
    Plugin::class => [
        'table'          => 'plugins',
        'classification' => 'not_tenancy',
        'reason'         => 'System-wide plugin installation registry (is_active/is_installed) — a plugin is installed for the whole system, not per company. Protected by Filament-Shield PluginPolicy.',
        'tracking'       => '#138',
    ],
    EmailLog::class => [
        'table'          => 'email_logs',
        'classification' => 'not_tenancy',
        'reason'         => 'System mail-send telemetry with no FK to any tenant-owned entity, no creator_id, no polymorphic link — structurally incapable of carrying a company dimension as currently modeled.',
        'tracking'       => '#138',
    ],
    Category::class => [
        'table'          => 'blogs_categories',
        'classification' => 'not_tenancy',
        'reason'         => 'Single public-site blog content — this app instance serves one website, not one website per company, and the migration has no company_id column by design.',
        'tracking'       => '#138',
    ],
    Post::class => [
        'table'          => 'blogs_posts',
        'classification' => 'not_tenancy',
        'reason'         => 'Single public-site blog content, same reasoning as Blog\\Category.',
        'tracking'       => '#138',
    ],
    Tag::class => [
        'table'          => 'blogs_tags',
        'classification' => 'not_tenancy',
        'reason'         => 'Single public-site blog content, same reasoning as Blog\\Category.',
        'tracking'       => '#138',
    ],
    Page::class => [
        'table'          => 'website_pages',
        'classification' => 'not_tenancy',
        'reason'         => 'Single public-site CMS content — same one-instance-one-site reasoning as the blogs plugin.',
        'tracking'       => '#138',
    ],
    TableView::class => [
        'table'          => 'table_views',
        'classification' => 'not_tenancy',
        'reason'         => 'Per-user saved UI filter state, never company data. The previously-confirmed IDOR (EditViewAction/deleteTableViewAction/replaceTableViewAction resolving any view by id with no ownership check) is closed: all three now go through TableView::resolveOwnedTableViewOrFail(id, filterable_type, user_id) — a single query requiring exact id + filterable_type + user_id match, so a public view can be read but never written by anyone but its owner. Covered by plugins/webkul/table-views/tests/Feature/TableViewOwnershipTest.php.',
        'tracking'       => '#138 PR4 ola4A',
    ],
    TableViewFavorite::class => [
        'table'          => 'table_view_favorites',
        'classification' => 'not_tenancy',
        'reason'         => 'Per-user favorite marker, never company data. user_id is always the caller\'s own Auth::id(), never accepted from the request. TableViewFavorite::toggleForOwnViewOrFail() additionally refuses to favorite a private view belonging to another user via TableView::assertVisibleOrFail(). Covered by plugins/webkul/table-views/tests/Feature/TableViewOwnershipTest.php.',
        'tracking'       => '#138 PR4 ola4A',
    ],
    ActivityTypeSuggestion::class => [
        'table'          => 'activity_type_suggestions',
        'classification' => 'not_tenancy',
        'reason'         => 'Pure many-to-many pairing between two ActivityType rows (composite FK, no id/timestamps, no owning entity) — structurally incapable of carrying a company dimension regardless of ActivityType\'s own pending scoping decision.',
        'tracking'       => '#138',
    ],

    // ---------------------------------------------------------------
    // global_reference — shared master/reference data
    // ---------------------------------------------------------------

    Currency::class => [
        'table'          => 'currencies',
        'classification' => 'global_reference',
        'reason'         => 'Shared ISO currency catalog referenced by every company via companies.currency_id — not per-company data itself.',
        'tracking'       => '#138',
    ],
    Webkul\Accounting\Models\Currency::class => [
        'table'          => 'currencies',
        'classification' => 'alias',
        'alias_of'       => Currency::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Support\\Models\\Currency.',
        'tracking'       => '#138',
    ],
    Webkul\Invoice\Models\Currency::class => [
        'table'          => 'currencies',
        'classification' => 'alias',
        'alias_of'       => Currency::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Support\\Models\\Currency.',
        'tracking'       => '#138',
    ],
    Webkul\Purchase\Models\Currency::class => [
        'table'          => 'currencies',
        'classification' => 'alias',
        'alias_of'       => Currency::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Support\\Models\\Currency.',
        'tracking'       => '#138',
    ],
    Webkul\Sale\Models\Currency::class => [
        'table'          => 'currencies',
        'classification' => 'alias',
        'alias_of'       => Currency::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Support\\Models\\Currency.',
        'tracking'       => '#138',
    ],
    Country::class => [
        'table'          => 'countries',
        'classification' => 'global_reference',
        'reason'         => 'Shared geography reference (ISO country list), no company dimension by design.',
        'tracking'       => '#138',
    ],
    State::class => [
        'table'          => 'states',
        'classification' => 'global_reference',
        'reason'         => 'Shared geography reference, child of Country — no company dimension by design.',
        'tracking'       => '#138',
    ],
    Bank::class => [
        'table'          => 'banks',
        'classification' => 'global_reference',
        'reason'         => 'Shared bank-institution master data (Odoo res.bank equivalent), referenced by partner bank accounts across companies — no company dimension by design.',
        'tracking'       => '#138',
    ],
    Webkul\Partner\Models\Bank::class => [
        'table'          => 'banks',
        'classification' => 'alias',
        'alias_of'       => Bank::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Support\\Models\\Bank.',
        'tracking'       => '#138',
    ],
    Webkul\Contact\Models\Bank::class => [
        'table'          => 'banks',
        'classification' => 'alias',
        'alias_of'       => Webkul\Partner\Models\Bank::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Partner\\Models\\Bank.',
        'tracking'       => '#138',
    ],
    UOM::class => [
        'table'          => 'unit_of_measures',
        'classification' => 'global_reference',
        'reason'         => 'Shared unit-of-measure catalog (kg, units, hours — Odoo uom.uom equivalent) referenced by products/orders across every company.',
        'tracking'       => '#138',
    ],
    UOMCategory::class => [
        'table'          => 'unit_of_measure_categories',
        'classification' => 'global_reference',
        'reason'         => 'Shared parent taxonomy for UOM — no company dimension by design.',
        'tracking'       => '#138',
    ],
    EmailTemplate::class => [
        'table'          => 'email_templates',
        'classification' => 'global_reference',
        'reason'         => 'Shared system content templates keyed by a unique `code` — no company dimension by design.',
        'tracking'       => '#138',
    ],
    Field::class => [
        'table'          => 'custom_fields',
        'classification' => 'global_reference',
        'reason'         => 'Custom-field DEFINITIONS (schema metadata: which fields exist on which model), unique on (code, customizable_type) — not per-row tenant DATA. The values entered into a custom field live on the owning record, which is scoped by that record\'s own contract.',
        'tracking'       => '#138',
    ],
    UTMMedium::class => [
        'table'          => 'utm_mediums',
        'classification' => 'global_reference',
        'reason'         => 'Shared marketing-attribution taxonomy (Odoo utm.medium equivalent) — global by design, no company dimension.',
        'tracking'       => '#138',
    ],
    Webkul\Recruitment\Models\UTMMedium::class => [
        'table'          => 'utm_mediums',
        'classification' => 'alias',
        'alias_of'       => UTMMedium::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Support\\Models\\UTMMedium.',
        'tracking'       => '#138',
    ],
    UTMSource::class => [
        'table'          => 'utm_sources',
        'classification' => 'global_reference',
        'reason'         => 'Shared marketing-attribution taxonomy (Odoo utm.source equivalent) — global by design, no company dimension.',
        'tracking'       => '#138',
    ],
    Webkul\Recruitment\Models\UTMSource::class => [
        'table'          => 'utm_sources',
        'classification' => 'alias',
        'alias_of'       => UTMSource::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Support\\Models\\UTMSource.',
        'tracking'       => '#138',
    ],
    UtmStage::class => [
        'table'          => 'utm_stages',
        'classification' => 'global_reference',
        'reason'         => 'UTM marketing-funnel stage taxonomy (awareness/consideration/...) — a distinct physical table from recruitments_stages/maintenance_stages/projects\' kanban stages, not a pipeline-stage model. Global by design, matches its UTMMedium/UTMSource siblings.',
        'tracking'       => '#138',
    ],

    // Product taxonomy — root is products.*, most-derived alias chains
    // fork through accounts/invoices for Category (verified via each
    // file's `use ... as Base*` import, #138 review 2026-07-19).
    Attribute::class => [
        'table'          => 'products_attributes',
        'classification' => 'global_reference',
        'reason'         => 'Shared product-attribute taxonomy (e.g. "Color", "Size") — no company dimension by design.',
        'tracking'       => '#138',
    ],
    Webkul\Accounting\Models\Attribute::class => [
        'table'          => 'products_attributes',
        'classification' => 'alias',
        'alias_of'       => Attribute::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Product\\Models\\Attribute.',
        'tracking'       => '#138',
    ],
    Webkul\Inventory\Models\Attribute::class => [
        'table'          => 'products_attributes',
        'classification' => 'alias',
        'alias_of'       => Attribute::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Product\\Models\\Attribute.',
        'tracking'       => '#138',
    ],
    Webkul\Invoice\Models\Attribute::class => [
        'table'          => 'products_attributes',
        'classification' => 'alias',
        'alias_of'       => Attribute::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Product\\Models\\Attribute.',
        'tracking'       => '#138',
    ],
    Webkul\Purchase\Models\Attribute::class => [
        'table'          => 'products_attributes',
        'classification' => 'alias',
        'alias_of'       => Attribute::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Product\\Models\\Attribute.',
        'tracking'       => '#138',
    ],
    Webkul\Sale\Models\Attribute::class => [
        'table'          => 'products_attributes',
        'classification' => 'alias',
        'alias_of'       => Attribute::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Product\\Models\\Attribute.',
        'tracking'       => '#138',
    ],
    AttributeOption::class => [
        'table'          => 'products_attribute_options',
        'classification' => 'global_reference',
        'reason'         => 'Options for a shared product Attribute (e.g. "Red"/"Blue" for "Color") — child of a global_reference model, itself global by design.',
        'tracking'       => '#138',
    ],
    Webkul\Product\Models\Category::class => [
        'table'          => 'products_categories',
        'classification' => 'global_reference',
        'reason'         => 'Shared product-category taxonomy — no company dimension by design.',
        'tracking'       => '#138',
    ],
    Webkul\Account\Models\Category::class => [
        'table'          => 'products_categories',
        'classification' => 'alias',
        'alias_of'       => Webkul\Product\Models\Category::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Product\\Models\\Category.',
        'tracking'       => '#138',
    ],
    Webkul\Inventory\Models\Category::class => [
        'table'          => 'products_categories',
        'classification' => 'alias',
        'alias_of'       => Webkul\Product\Models\Category::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Product\\Models\\Category.',
        'tracking'       => '#138',
    ],
    Webkul\Accounting\Models\Category::class => [
        'table'          => 'products_categories',
        'classification' => 'alias',
        'alias_of'       => Webkul\Account\Models\Category::class,
        'reason'         => 'Extends Webkul\\Account\\Models\\Category (accounting reuses the accounts-domain alias chain).',
        'tracking'       => '#138',
    ],
    Webkul\Invoice\Models\Category::class => [
        'table'          => 'products_categories',
        'classification' => 'alias',
        'alias_of'       => Webkul\Account\Models\Category::class,
        'reason'         => 'Extends Webkul\\Account\\Models\\Category (invoices reuses the accounts-domain alias chain).',
        'tracking'       => '#138',
    ],
    Webkul\Purchase\Models\Category::class => [
        'table'          => 'products_categories',
        'classification' => 'alias',
        'alias_of'       => Webkul\Invoice\Models\Category::class,
        'reason'         => 'Extends Webkul\\Invoice\\Models\\Category (purchases reuses the invoices-domain alias chain).',
        'tracking'       => '#138',
    ],
    Webkul\Sale\Models\Category::class => [
        'table'          => 'products_categories',
        'classification' => 'alias',
        'alias_of'       => Webkul\Invoice\Models\Category::class,
        'reason'         => 'Extends Webkul\\Invoice\\Models\\Category (sales reuses the invoices-domain alias chain).',
        'tracking'       => '#138',
    ],
    Webkul\Product\Models\Tag::class => [
        'table'          => 'products_tags',
        'classification' => 'global_reference',
        'reason'         => 'Shared product tag taxonomy — no FK to a specific tenant-owned Product row required, same shape as every other Tag model in the codebase (unique name, no company_id).',
        'tracking'       => '#138',
    ],

    // ---------------------------------------------------------------
    // parent_scoped — real, cited enforcement via an already-scoped
    // parent/pivot. Every entry below cites the mechanism, not just
    // "nothing queries it directly".
    // ---------------------------------------------------------------

    Account::class => [
        'table'          => 'accounts_accounts',
        'classification' => 'parent_scoped',
        'reason'         => 'Deliberately has no company_id column of its own — visibility is validated via a many-to-many pivot to Company (accounts_account_companies), documented and reviewed in PR #17 (plugins/webkul/accounts/src/Models/Account.php:111,200).',
        'tracking'       => '#138 / PR #17',
    ],
    Milestone::class => [
        'table'          => 'projects_milestones',
        'classification' => 'parent_scoped',
        'reason'         => 'Deliberately has no company_id column — its mandatory (non-nullable, cascadeOnDelete) parent Project is HasCompanyScope-enforced (plugins/webkul/projects/src/Models/Project.php), and Milestone::booted() adds a global scope requiring whereHas(\'project\') (plugins/webkul/projects/src/Models/Milestone.php), inheriting Project\'s own CompanyScope filter for reads. Writes are validated by resolveEffectiveCompanyIdOrFail() against the persisted Project, and MilestonePolicy::belongsToAllowedCompany() re-checks the same on every view/update/delete. Covered by plugins/webkul/projects/tests/Feature/MilestoneCompanyScopeTest.php.',
        'tracking'       => '#138 PR4 ola4A',
    ],
    Webkul\Accounting\Models\Account::class => [
        'table'          => 'accounts_accounts',
        'classification' => 'alias',
        'alias_of'       => Account::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Account\\Models\\Account.',
        'tracking'       => '#138',
    ],
    AccountAccountTag::class => [
        'table'          => 'accounts_account_account_tags',
        'classification' => 'parent_scoped',
        'reason'         => 'Pivot between Account (parent_scoped via accounts_account_companies) and Tag (global_reference) — no independent listing surface.',
        'tracking'       => '#138',
    ],
    AccountJournal::class => [
        'table'          => 'accounts_account_journals',
        'classification' => 'parent_scoped',
        'reason'         => 'Pivot whose journal_id FK targets accounts_journals (Journal, HasCompanyScope — plugins/webkul/accounts/src/Models/Journal.php:25).',
        'tracking'       => '#138',
    ],
    AccountPaymentRegisterMoveLine::class => [
        'table'          => 'accounts_account_payment_register_move_lines',
        'classification' => 'parent_scoped',
        'reason'         => 'Pivot between PaymentRegister and MoveLine, both HasCompanyScope (already reviewed in PR #17).',
        'tracking'       => '#138 / PR #17',
    ],
    AccountTax::class => [
        'table'          => 'accounts_account_taxes',
        'classification' => 'parent_scoped',
        'reason'         => 'Pivot whose tax_id FK targets accounts_taxes (Tax, HasCompanyScope — plugins/webkul/accounts/src/Models/Tax.php:26).',
        'tracking'       => '#138',
    ],
    FullReconcile::class => [
        'table'          => 'accounts_full_reconciles',
        'classification' => 'parent_scoped',
        'reason'         => 'exchange_move_id FK targets accounts_account_moves (Move, HasCompanyScope — plugins/webkul/accounts/src/Models/Move.php:37).',
        'tracking'       => '#138',
    ],
    JournalAccount::class => [
        'table'          => 'accounts_journal_accounts',
        'classification' => 'parent_scoped',
        'reason'         => 'journal_id FK targets accounts_journals (Journal, HasCompanyScope).',
        'tracking'       => '#138',
    ],
    PaymentDueTerm::class => [
        'table'          => 'accounts_payment_due_terms',
        'classification' => 'parent_scoped',
        'reason'         => 'payment_id FK targets accounts_payment_terms (PaymentTerm, HasCompanyScope — plugins/webkul/accounts/src/Models/PaymentTerm.php:22).',
        'tracking'       => '#138',
    ],
    PaymentMethodLine::class => [
        'table'          => 'accounts_payment_method_lines',
        'classification' => 'parent_scoped',
        'reason'         => 'journal_id FK targets accounts_journals (HasCompanyScope); payment_account_id FK targets accounts_accounts (parent_scoped via the Company pivot above).',
        'tracking'       => '#138',
    ],
    PaymentMethod::class => [
        'table'          => 'accounts_payment_methods',
        'classification' => 'global_reference',
        'reason'         => 'Global registry of payment-method TYPES (fields: code/payment_type/name only, no FK to any scoped entity) — not a per-company configuration row.',
        'tracking'       => '#138',
    ],
    ProductSupplierTaxes::class => [
        'table'          => 'accounts_product_supplier_taxes',
        'classification' => 'parent_scoped',
        'reason'         => 'product_id FK targets products_products (Product, HasCompanyScope); tax_id FK targets accounts_taxes (HasCompanyScope).',
        'tracking'       => '#138',
    ],
    ProductTaxes::class => [
        'table'          => 'accounts_product_taxes',
        'classification' => 'parent_scoped',
        'reason'         => 'product_id FK targets products_products (Product, HasCompanyScope); tax_id FK targets accounts_taxes (HasCompanyScope).',
        'tracking'       => '#138',
    ],
    Webkul\Account\Models\Tag::class => [
        'table'          => 'accounts_account_tags',
        'classification' => 'global_reference',
        'reason'         => 'Global tag taxonomy (fields: country_id [global ref] / color / name only, no FK to a scoped entity).',
        'tracking'       => '#138',
    ],
    TaxTaxes::class => [
        'table'          => 'accounts_tax_taxes',
        'classification' => 'parent_scoped',
        'reason'         => 'Both FKs target accounts_taxes (Tax, HasCompanyScope) — a self-referential pivot between already-scoped rows.',
        'tracking'       => '#138',
    ],
    Incoterm::class => [
        'table'          => 'accounts_incoterms',
        'classification' => 'global_reference',
        'reason'         => 'Global Incoterm reference codes (international commercial trade terms) — no company dimension by design.',
        'tracking'       => '#138',
    ],
    Webkul\Accounting\Models\Incoterm::class => [
        'table'          => 'accounts_incoterms',
        'classification' => 'alias',
        'alias_of'       => Incoterm::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Account\\Models\\Incoterm.',
        'tracking'       => '#138',
    ],
    Webkul\Invoice\Models\Incoterm::class => [
        'table'          => 'accounts_incoterms',
        'classification' => 'alias',
        'alias_of'       => Incoterm::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Account\\Models\\Incoterm.',
        'tracking'       => '#138',
    ],
    CashRounding::class => [
        'table'          => 'accounts_cash_roundings',
        'classification' => 'global_reference',
        'reason'         => 'Global cash-rounding method reference (migration has no company_id column) — already reviewed in the PR #17 domain audit.',
        'tracking'       => '#138 / PR #17',
    ],
    Webkul\Accounting\Models\CashRounding::class => [
        'table'          => 'accounts_cash_roundings',
        'classification' => 'alias',
        'alias_of'       => CashRounding::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Account\\Models\\CashRounding.',
        'tracking'       => '#138',
    ],
    OrderLine::class => [
        'table'          => 'sales_order_lines',
        'classification' => 'parent_scoped',
        'reason'         => 'Real, cited enforcement: ValidatesRelatedCompanyScope derives company_id from the owning Order on create/update, and assertRelatedBelongsToCompany() validates product_id and product_packaging_id both ways (plugins/webkul/sales/src/Models/OrderLine.php:184-215) — not a passive "nothing queries it" case.',
        'tracking'       => '#138',
    ],
    ProductAttribute::class => [
        'table'          => 'products_product_attributes',
        'classification' => 'parent_scoped',
        'reason'         => 'belongsTo(Product::class) — Product is HasCompanyScope (plugins/webkul/products/src/Models/ProductAttribute.php:36).',
        'tracking'       => '#138',
    ],
    ProductAttributeValue::class => [
        'table'          => 'products_product_attribute_values',
        'classification' => 'parent_scoped',
        'reason'         => 'belongsTo(Product::class) — Product is HasCompanyScope (plugins/webkul/products/src/Models/ProductAttributeValue.php:25).',
        'tracking'       => '#138',
    ],
    ProductCombination::class => [
        'table'          => 'products_product_combinations',
        'classification' => 'parent_scoped',
        'reason'         => 'belongsTo(Product::class, \'product_id\') — Product is HasCompanyScope (plugins/webkul/products/src/Models/ProductCombination.php:22).',
        'tracking'       => '#138',
    ],
    ProcurementGroup::class => [
        'table'          => 'inventories_procurement_groups',
        'classification' => 'parent_scoped',
        'reason'         => 'Reached only via relations from already-HasCompanyScope\'d parents (Sale Order, Purchase Order, Manufacturing Order, Move, Operation, Rule) — no standalone Filament resource or controller exists for it (already reviewed in the PR #17 domain audit).',
        'tracking'       => '#138 / PR #17',
    ],
    Webkul\Manufacturing\Models\ProcurementGroup::class => [
        'table'          => 'inventories_procurement_groups',
        'classification' => 'alias',
        'alias_of'       => ProcurementGroup::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Inventory\\Models\\ProcurementGroup.',
        'tracking'       => '#138',
    ],
    PackageDestination::class => [
        'table'          => 'inventories_package_destinations',
        'classification' => 'parent_scoped',
        'reason'         => 'operation_id FK targets inventories_operations (Operation, HasCompanyScope) — already reviewed in the PR #17 domain audit; no consumers outside its own factory were found.',
        'tracking'       => '#138 / PR #17',
    ],
    ProductQuantityRelocation::class => [
        'table'          => 'inventories_product_quantity_relocations',
        'classification' => 'parent_scoped',
        'reason'         => 'destinationLocation FK targets Location (HasCompanyScope); destinationPackage FK targets Package (HasCompanyScope) — already reviewed in the PR #17 domain audit.',
        'tracking'       => '#138 / PR #17',
    ],
    StorageCategoryCapacity::class => [
        'table'          => 'inventories_storage_category_capacities',
        'classification' => 'parent_scoped',
        'reason'         => 'product_id FK targets Product (HasCompanyScope); storage_category_id FK targets StorageCategory (HasCompanyScope); package_type_id FK targets PackageType (HasCompanyScope) — already reviewed in the PR #17 domain audit.',
        'tracking'       => '#138 / PR #17',
    ],
    Webkul\Inventory\Models\Tag::class => [
        'table'          => 'inventories_tags',
        'classification' => 'global_reference',
        'reason'         => 'Global tag taxonomy, no FK to a scoped entity — identical shape to products.Tag/accounts.Tag, already reviewed in the PR #17 domain audit.',
        'tracking'       => '#138 / PR #17',
    ],
    OrderGroup::class => [
        'table'          => 'purchases_order_groups',
        'classification' => 'parent_scoped',
        'reason'         => 'Only consumer is Order::group() belongsTo (plugins/webkul/purchases/src/Models/Order.php:129) — Order is HasCompanyScope; no standalone Filament resource exists.',
        'tracking'       => '#138',
    ],
    WorkOrder::class => [
        'table'          => 'manufacturing_work_orders',
        'classification' => 'parent_scoped',
        'reason'         => 'order_id FK targets manufacturing_orders (Order, HasCompanyScope) — code-documented in WorkOrder.php with an explicit "#138 review round 2, 2026-07-18" comment.',
        'tracking'       => '#138',
    ],
    WorkCenterCapacity::class => [
        'table'          => 'manufacturing_work_center_capacities',
        'classification' => 'parent_scoped',
        'reason'         => 'work_center_id FK targets manufacturing_work_centers (WorkCenter, HasCompanyScope) — code-documented with an explicit "#138 review round 2, 2026-07-18" comment.',
        'tracking'       => '#138',
    ],
    Operation::class => [
        'table'          => 'manufacturing_operations',
        'classification' => 'parent_scoped',
        'reason'         => 'bill_of_material_id FK targets manufacturing_bills_of_materials (BillOfMaterial, HasCompanyScope) — code-documented with an explicit "#138 review round 2, 2026-07-18" comment.',
        'tracking'       => '#138',
    ],
    WorkCenterLossType::class => [
        'table'          => 'manufacturing_work_center_loss_types',
        'classification' => 'global_reference',
        'reason'         => 'Global taxonomy (no FK to a scoped entity) — already reviewed in the PR #17 domain audit.',
        'tracking'       => '#138 / PR #17',
    ],
    WorkCenterTag::class => [
        'table'          => 'manufacturing_work_center_tags',
        'classification' => 'global_reference',
        'reason'         => 'Global tag taxonomy (name/color only, no FK to a scoped entity) — already reviewed in the PR #17 domain audit.',
        'tracking'       => '#138 / PR #17',
    ],
    WorkCenterProductivityLoss::class => [
        'table'          => 'manufacturing_work_center_productivity_losses',
        'classification' => 'global_reference',
        'reason'         => 'loss_type_id FK targets manufacturing_work_center_loss_types (itself global_reference) — a global taxonomy chain, already reviewed in the PR #17 domain audit.',
        'tracking'       => '#138 / PR #17',
    ],

    // ---------------------------------------------------------------
    // global_reference — HR/recruitment lookup tables (standalone,
    // no FK to a currently-unscoped tenant-owned parent). Children and
    // pivots that DO hang off Employee/JobPosition/Department/Candidate/
    // Applicant/Calendar/ActivityPlan/LeaveType/Team (all still
    // unscoped) are deliberately left OUT of this manifest — they stay
    // real_gap_without_company_column until their parent is scoped and
    // the child actually validates against it.
    // ---------------------------------------------------------------

    EmployeeCategory::class => [
        'table'          => 'employees_categories',
        'classification' => 'global_reference',
        'reason'         => 'Standalone HR taxonomy (globally-unique name), no FK to Employee or any tenant-owned entity.',
        'tracking'       => '#138',
    ],
    DepartureReason::class => [
        'table'          => 'employees_departure_reasons',
        'classification' => 'global_reference',
        'reason'         => 'Standalone HR taxonomy, no FK to a tenant-owned entity.',
        'tracking'       => '#138',
    ],
    EmployeeResumeLineType::class => [
        'table'          => 'employees_employee_resume_line_types',
        'classification' => 'global_reference',
        'reason'         => 'Standalone resume-section-type taxonomy ("Experience"/"Education"), no FK to a tenant-owned entity.',
        'tracking'       => '#138',
    ],
    EmploymentType::class => [
        'table'          => 'employees_employment_types',
        'classification' => 'global_reference',
        'reason'         => 'Standalone HR taxonomy — has a country_id FK (geography, itself global_reference), no company FK.',
        'tracking'       => '#138',
    ],
    Webkul\Recruitment\Models\EmploymentType::class => [
        'table'          => 'employees_employment_types',
        'classification' => 'alias',
        'alias_of'       => EmploymentType::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Employee\\Models\\EmploymentType.',
        'tracking'       => '#138',
    ],
    SkillType::class => [
        'table'          => 'employees_skill_types',
        'classification' => 'global_reference',
        'reason'         => 'Standalone HR taxonomy, no FK to a tenant-owned entity.',
        'tracking'       => '#138',
    ],
    Webkul\Recruitment\Models\SkillType::class => [
        'table'          => 'employees_skill_types',
        'classification' => 'alias',
        'alias_of'       => SkillType::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Employee\\Models\\SkillType.',
        'tracking'       => '#138',
    ],
    SkillLevel::class => [
        'table'          => 'employees_skill_levels',
        'classification' => 'global_reference',
        'reason'         => 'Child of SkillType (itself global_reference) — a fully global taxonomy chain, no FK to a tenant-owned entity.',
        'tracking'       => '#138',
    ],
    Skill::class => [
        'table'          => 'employees_skills',
        'classification' => 'global_reference',
        'reason'         => 'Standalone HR taxonomy — FK only to skill_type_id (itself global_reference), no company FK.',
        'tracking'       => '#138',
    ],
    ApplicantCategory::class => [
        'table'          => 'recruitments_applicant_categories',
        'classification' => 'global_reference',
        'reason'         => 'Standalone kanban-style category taxonomy, no FK to Applicant or any tenant-owned entity — the pivot to Applicant (ApplicantApplicantCategory) is a separate table left as a real gap since Applicant itself is still unscoped.',
        'tracking'       => '#138',
    ],
    Degree::class => [
        'table'          => 'recruitments_degrees',
        'classification' => 'global_reference',
        'reason'         => 'Standalone academic-degree taxonomy, no FK to a tenant-owned entity.',
        'tracking'       => '#138',
    ],
    RefuseReason::class => [
        'table'          => 'recruitments_refuse_reasons',
        'classification' => 'global_reference',
        'reason'         => 'Standalone taxonomy, no FK to a tenant-owned entity.',
        'tracking'       => '#138',
    ],
    Stage::class => [
        'table'          => 'maintenance_stages',
        'classification' => 'global_reference',
        'reason'         => 'Approved contract (#138 review, 2026-07-19): maintenance Kanban stages are explicitly classified as shared reference data, distinct from Team/EquipmentCategory/Equipment/MaintenanceRequest which remain strict_company real gaps.',
        'tracking'       => '#138',
    ],
    Webkul\Project\Models\Tag::class => [
        'table'          => 'projects_tags',
        'classification' => 'global_reference',
        'reason'         => 'Standalone taxonomy (globally-unique name, no FK, no parent) — same shape as every other Tag model in the codebase.',
        'tracking'       => '#138',
    ],

    // ---------------------------------------------------------------
    // global_reference — remaining identity plugin lookups (partners/
    // contacts), no FK to a scoped entity.
    // ---------------------------------------------------------------

    Industry::class => [
        'table'          => 'partners_industries',
        'classification' => 'global_reference',
        'reason'         => 'Standalone industry taxonomy, no FK to a tenant-owned entity.',
        'tracking'       => '#138',
    ],
    Webkul\Contact\Models\Industry::class => [
        'table'          => 'partners_industries',
        'classification' => 'alias',
        'alias_of'       => Industry::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Partner\\Models\\Industry.',
        'tracking'       => '#138',
    ],
    Webkul\Partner\Models\Tag::class => [
        'table'          => 'partners_tags',
        'classification' => 'global_reference',
        'reason'         => 'Standalone label taxonomy — the only relation is a M2M pivot to Partner (itself global_party_identity), not to Company.',
        'tracking'       => '#138',
    ],
    Webkul\Contact\Models\Tag::class => [
        'table'          => 'partners_tags',
        'classification' => 'alias',
        'alias_of'       => Webkul\Partner\Models\Tag::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Partner\\Models\\Tag.',
        'tracking'       => '#138',
    ],
    Title::class => [
        'table'          => 'partners_titles',
        'classification' => 'global_reference',
        'reason'         => 'Standalone honorific taxonomy (Mr./Mrs./Dr.), no FK to a tenant-owned entity.',
        'tracking'       => '#138',
    ],
    Webkul\Contact\Models\Title::class => [
        'table'          => 'partners_titles',
        'classification' => 'alias',
        'alias_of'       => Title::class,
        'reason'         => 'Plugin-scoped alias of Webkul\\Partner\\Models\\Title.',
        'tracking'       => '#138',
    ],

    // Note: partners_bank_accounts (BankAccount, all 4 plugin classes) is
    // deliberately NOT in this manifest. It remains a real gap — the
    // approved contract adds a partners_bank_account_companies membership
    // pivot, not yet implemented (see docs/security/company-scope-pr4-inventory.md).
];
