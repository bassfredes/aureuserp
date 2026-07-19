<?php

declare(strict_types=1);

/**
 * Formally reviewed exceptions to the company-scope auditor
 * (scripts/audit-company-scope.php). Every entry documents an
 * already-authorized decision (#138) for a model that intentionally has no
 * per-row CompanyScope enforcement.
 *
 * This is the ONLY sanctioned way to silence a `missing_scope` finding — the
 * auditor does not accept namespace, regex, or short-name exclusions
 * (#138 review, 2026-07-19: those are too easy to widen by accident and
 * would hide a genuine future regression on an unrelated class).
 *
 * Every entry is validated on every run, regardless of --plugins scope:
 *   - the class must exist and be autoloadable;
 *   - `table` must match the model's actual table right now;
 *   - `classification` must be one of App\Support\CompanyScopeAudit\
 *     ExceptionManifest::CLASSIFICATIONS;
 *   - if the model has since gained real HasCompanyScope enforcement, the
 *     entry is "stale" and must be removed, not left in place.
 *
 * @return array<class-string, array{table: string, classification: string, reason: string, tracking: string}>
 */
return [
    \Webkul\Partner\Models\Partner::class => [
        'table'          => 'partners_partners',
        'classification' => 'global_party_identity',
        'reason'         => 'Canonical party identity (Odoo res.partner equivalent) — a partner/customer/vendor is visible and referenceable across every company it transacts with by design. Isolation for financial write paths that touch it (Move, PaymentRegister, MoveReversal, ...) is enforced at those write paths, not on Partner itself.',
        'tracking'       => '#138',
    ],
    \Webkul\Contact\Models\Partner::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'reason'         => 'Zero-logic UI-binding subclass of Webkul\\Partner\\Models\\Partner (contacts is the nav-registered layer, partners owns the schema). Same table, same row identity, inherits the global_party_identity decision above.',
        'tracking'       => '#138',
    ],
    \Webkul\Accounting\Models\Partner::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'reason'         => 'Extends Webkul\\Account\\Models\\Partner (accounting reuses the accounts-domain alias chain), ultimately the same table/identity.',
        'tracking'       => '#138',
    ],
    \Webkul\Accounting\Models\Customer::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'reason'         => 'Extends Webkul\\Account\\Models\\Customer (same table, filtered by account_type at the relation level, not a distinct schema). Inherits global_party_identity.',
        'tracking'       => '#138',
    ],
    \Webkul\Accounting\Models\Vendor::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'reason'         => 'Extends Webkul\\Account\\Models\\Vendor (same table). Inherits global_party_identity.',
        'tracking'       => '#138',
    ],
    \Webkul\Account\Models\Partner::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'reason'         => 'Plugin-scoped alias of Webkul\\Partner\\Models\\Partner used by the accounts (Journal/Move/MoveLine/...) domain, already reviewed in PR #17. Same table/identity.',
        'tracking'       => '#138',
    ],
    \Webkul\Account\Models\Customer::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'reason'         => 'Extends Webkul\\Account\\Models\\Partner directly (same plugin, account_type-filtered subclass, not a distinct schema). Inherits global_party_identity.',
        'tracking'       => '#138',
    ],
    \Webkul\Account\Models\Vendor::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'reason'         => 'Extends Webkul\\Account\\Models\\Partner directly (same plugin, account_type-filtered subclass, not a distinct schema). Inherits global_party_identity.',
        'tracking'       => '#138',
    ],
    \Webkul\Invoice\Models\Partner::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'reason'         => 'Extends Webkul\\Account\\Models\\Partner (invoices reuses the accounts-domain alias chain, already reviewed in PR #17), ultimately the same table/identity.',
        'tracking'       => '#138',
    ],
    \Webkul\Invoice\Models\Customer::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'reason'         => 'Extends Webkul\\Account\\Models\\Customer (same table). Inherits global_party_identity.',
        'tracking'       => '#138',
    ],
    \Webkul\Invoice\Models\Vendor::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'reason'         => 'Extends Webkul\\Account\\Models\\Vendor (same table). Inherits global_party_identity.',
        'tracking'       => '#138',
    ],
    \Webkul\Purchase\Models\Partner::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'reason'         => 'Extends Webkul\\Account\\Models\\Partner (purchases reuses the accounts-domain alias chain), ultimately the same table/identity.',
        'tracking'       => '#138',
    ],
    \Webkul\Sale\Models\Partner::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'reason'         => 'Plugin-scoped alias of Webkul\\Invoice\\Models\\Partner (sales reuses the invoices-domain alias chain), ultimately the same table/identity.',
        'tracking'       => '#138',
    ],
    \Webkul\Website\Models\Partner::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'reason'         => 'Customer-portal-login subtype of Partner (adds password/email_verified_at for self-service login) — the documented ADR 0007 portal exception, not a separate tenant identity.',
        'tracking'       => '#138 / ADR 0007',
    ],
    \Webkul\Partner\Models\Address::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'reason'         => 'Address is Partner itself (same table, same class hierarchy), distinguished only by an account_type=\'address\' relation-level scope, not a separate schema. A global scope keys to the table, so it cannot be classified independently of Partner — inherits global_party_identity.',
        'tracking'       => '#138',
    ],
    \Webkul\Contact\Models\Address::class => [
        'table'          => 'partners_partners',
        'classification' => 'alias',
        'reason'         => 'Zero-logic UI-binding subclass of Webkul\\Partner\\Models\\Address. Same table/identity as Partner.',
        'tracking'       => '#138',
    ],
];
