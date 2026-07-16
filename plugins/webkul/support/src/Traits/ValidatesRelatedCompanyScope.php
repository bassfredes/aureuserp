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
     * $relatedId itself. A null $relatedId is a no-op: required-field
     * validation for it is the caller's own concern, not this trait's.
     *
     * Strict equality, always — a null $companyId does NOT mean "shared",
     * and a null $related->company_id does NOT mean "shared" either.
     * Product/Packaging are strict_company in this rollout, so any
     * asymmetry (one side null, the other not) is itself a mismatch. The
     * lookup includes soft-deleted rows: a soft-deleted cross-company
     * Product must still be caught, not silently pass because `find()`
     * couldn't see it.
     */
    protected static function assertRelatedBelongsToCompany(?int $relatedId, string $relatedClass, string $label, ?int $companyId): void
    {
        if ($relatedId === null) {
            return;
        }

        $query = $relatedClass::withoutGlobalScope(CompanyScope::class);

        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($relatedClass), true)) {
            $query = $query->withTrashed();
        }

        $related = $query->find($relatedId);

        if (! $related) {
            return;
        }

        if ((int) $related->company_id !== (int) $companyId) {
            throw new AuthorizationException("The related {$label} belongs to a different company.");
        }
    }

    /**
     * Resolves the effective company_id from the owning parent aggregate
     * (Order, Operation, Move, ...) rather than trusting the child's own
     * mutable company_id column — a child's company_id can be set to
     * anything by a write path that doesn't go through the parent, so
     * the parent's real, persisted company_id is the only authoritative
     * source (D5b review round 1, aureuserp#137).
     *
     * If the child already carries an explicit company_id that conflicts
     * with the parent's, that is rejected outright rather than silently
     * overwritten — a caller passing a mismatched company_id alongside a
     * correct parent_id is exactly the case this guards against. When no
     * parent is resolvable (missing id, parent not found, or a genuinely
     * parent-less/standalone record), the child's own company_id is kept
     * as-is.
     */
    protected static function resolveEffectiveCompanyId(?int $parentId, string $parentClass, ?int $childCompanyId, string $parentLabel): ?int
    {
        if ($parentId === null) {
            return $childCompanyId;
        }

        $query = $parentClass::withoutGlobalScope(CompanyScope::class);

        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($parentClass), true)) {
            $query = $query->withTrashed();
        }

        $parent = $query->find($parentId);

        if (! $parent || $parent->company_id === null) {
            return $childCompanyId;
        }

        if ($childCompanyId !== null && (int) $childCompanyId !== (int) $parent->company_id) {
            throw new AuthorizationException("The company_id does not match the {$parentLabel}'s company.");
        }

        return (int) $parent->company_id;
    }
}
