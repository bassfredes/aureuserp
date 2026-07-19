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

        // Fail closed on NULL, on either side (D5b review round 3,
        // aureuserp#137): casting both sides to int before comparing
        // turned NULL/NULL into 0 === 0, silently accepting a relation
        // with no company on either end as a "match". Product/Packaging
        // are strict_company in this rollout — a related FK is present,
        // so NULL can never stand in for a valid company on either side.
        if ($companyId === null || $related->company_id === null || (int) $related->company_id !== (int) $companyId) {
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

        if (! $parent) {
            return $childCompanyId;
        }

        // A resolved parent is authoritative and must fail closed here
        // (D5b review round 3, aureuserp#137): if the parent itself has
        // no company of its own, it cannot vouch for any company on the
        // child's behalf — falling back to the child's own company_id
        // would let a write path smuggle an arbitrary company past a
        // parent that never actually claimed it.
        if ($parent->company_id === null) {
            throw new AuthorizationException("The {$parentLabel} has no company of its own to anchor to.");
        }

        if ($childCompanyId !== null && (int) $childCompanyId !== (int) $parent->company_id) {
            throw new AuthorizationException("The company_id does not match the {$parentLabel}'s company.");
        }

        return (int) $parent->company_id;
    }

    /**
     * Strict sibling of resolveEffectiveCompanyId() for children that are
     * never parent-less and never strict_company-with-a-shared-parent:
     * a missing/soft-deleted-and-not-withTrashed parent or a parent with no
     * company of its own is a hard failure here, not a silent fallback to
     * the child's own (unauthoritative) company_id. Use this for any
     * strict_company child whose parent FK is semantically mandatory even
     * where the DB column allows NULL (#138 review, 2026-07-18 — the plain
     * resolveEffectiveCompanyId() silently keeping the child's company on a
     * missing parent let a row "move" to an arbitrary company by pointing
     * it at a nonexistent parent id).
     *
     * The parent is resolved with CompanyScope bypassed (by design — the
     * child's own company must be derivable even from a parent the acting
     * user cannot see), so the resolved company must still be
     * write-authorized before being handed back: an actor who only knows a
     * hidden parent's id must not be able to create a child anchored to
     * that parent's company merely by pointing at it (#138 review round 2,
     * 2026-07-18 — e.g. a BankStatementLine under another company's hidden
     * BankStatement).
     */
    protected static function resolveEffectiveCompanyIdOrFail(?int $parentId, string $parentClass, ?int $childCompanyId, string $parentLabel): int
    {
        if ($parentId === null) {
            throw new AuthorizationException("A {$parentLabel} is required to resolve the company.");
        }

        $query = $parentClass::withoutGlobalScope(CompanyScope::class);

        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($parentClass), true)) {
            $query = $query->withTrashed();
        }

        $parent = $query->find($parentId);

        if (! $parent) {
            throw new AuthorizationException("The {$parentLabel} could not be found.");
        }

        if ($parent->company_id === null) {
            throw new AuthorizationException("The {$parentLabel} has no company of its own to anchor to.");
        }

        if ($childCompanyId !== null && (int) $childCompanyId !== (int) $parent->company_id) {
            throw new AuthorizationException("The company_id does not match the {$parentLabel}'s company.");
        }

        CompanyScope::assertCanWriteCompany((int) $parent->company_id);

        return (int) $parent->company_id;
    }
}
