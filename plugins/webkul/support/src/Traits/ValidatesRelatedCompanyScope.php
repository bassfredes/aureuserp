<?php

namespace Webkul\Support\Traits;

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Support\Models\Scopes\CompanyScope;

/**
 * Model-level counterpart to Webkul\Support\Http\Concerns\ValidatesCompanyScope
 * (D5b, aureuserp#137): that trait's assertRelatedRecordAccessible() only
 * runs inside controllers, so any other write path to the same model —
 * Filament resources/relation managers, factories, internal services —
 * never validated that a related record (Product, Packaging, ...) actually
 * belongs to the same company as the aggregate referencing it. Read
 * isolation (CompanyScope hiding another company's row) is not the same
 * guarantee as relation integrity (this aggregate may not reference that
 * row even when the acting user can see both individually) — the same
 * lesson already closed for Packaging/ProductSupplier/PriceRuleItem in
 * aureuserp#11, now applied to the aggregates that reference Product and
 * Packaging across sales/purchases/inventories.
 *
 * Unlike HasCompanyScope's own per-model invariants (which derive their
 * own company_id FROM the related record when absent), these aggregates
 * already have an authoritative company_id of their own — inherited from
 * their parent Order/Operation/etc., not from the Product/Packaging being
 * referenced. This trait only ever compares, never derives.
 */
trait ValidatesRelatedCompanyScope
{
    /**
     * $companyId is the aggregate's own already-resolved effective
     * company — never Auth::user()'s default, and never derived from
     * $relatedId itself. A null $relatedId or $companyId is a no-op:
     * required-field validation for either is the caller's own concern,
     * not this trait's.
     */
    protected static function assertRelatedBelongsToCompany(?int $relatedId, string $relatedClass, string $label, ?int $companyId): void
    {
        if ($relatedId === null || $companyId === null) {
            return;
        }

        $related = $relatedClass::withoutGlobalScope(CompanyScope::class)->find($relatedId);

        if (! $related) {
            return;
        }

        if ($related->company_id !== null && (int) $related->company_id !== (int) $companyId) {
            throw new AuthorizationException("The related {$label} belongs to a different company.");
        }
    }
}
