<?php

namespace Webkul\Support\Http\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Webkul\Support\Models\Scopes\CompanyScope;

/**
 * Shared write-path guards for controllers of HasCompanyScope models.
 * CompanyScope only filters reads: a client-submitted company_id, or a
 * tenant-scoped foreign key (parent location, warehouse, route,
 * operation type, ...), passes FormRequest's exists:table,id validation
 * regardless of which company it belongs to — that rule only confirms
 * the row is real, not that the acting user may reference it. Every
 * create/update path for a model in the company-scope rollout must call
 * these explicitly; skipping one reopens the exact cross-company
 * injection class of bug fixed for Location in aureuserp#5.
 */
trait ValidatesCompanyScope
{
    /**
     * A create payload's company_id (if submitted) must be one the
     * acting user is actually allowed to operate on.
     */
    protected function assertCompanyIdAllowed(?int $companyId, ?Authenticatable $user, string $label = 'record'): void
    {
        if ($companyId === null) {
            return;
        }

        if (! CompanyScope::allowedCompanyIds($user)->contains($companyId)) {
            throw new AuthorizationException("You are not allowed to create or move a {$label} into this company.");
        }
    }

    /**
     * company_id must never change after creation — archive/deactivate
     * and create a new record instead, matching Location's own
     * updating() guard. Applies regardless of whether the submitted
     * value would otherwise be an "allowed" company: this blocks
     * re-parenting a record into a different company outright, not just
     * into an unauthorized one.
     *
     * Takes the whole validated $data array and checks array_key_exists,
     * not $data['company_id'] ?? null — that idiom can't distinguish "the
     * client didn't send this field" (skip the check, nothing to guard)
     * from "the client explicitly sent company_id: null" (a real attempt
     * to change it, which must be rejected exactly like any other value
     * change, including on strict_company models with no null state).
     */
    protected function assertCompanyIdImmutable(array $data, Model $model, string $label = 'record', string $key = 'company_id'): void
    {
        if (! array_key_exists($key, $data)) {
            return;
        }

        // $data comes straight from an array, not a type-hinted parameter,
        // so unlike assertCompanyIdAllowed()/assertRelatedRecordAccessible()
        // (both ?int params PHP coerces at the call boundary) a numeric
        // string like "1" here would never loosely-equal the model's own
        // int attribute under strict !==. Normalize both sides first.
        $submitted = $data[$key] === null ? null : (int) $data[$key];
        $current = $model->{$key} === null ? null : (int) $model->{$key};

        if ($submitted !== $current) {
            throw new AuthorizationException("Changing the company of this {$label} is forbidden at this point, you should rather archive it and create a new one.");
        }
    }

    /**
     * A related tenant-scoped id must resolve through the scoped model
     * lookup, not FormRequest's exists:table,id (Model::find() returns
     * null both for a genuinely missing id and for one belonging to
     * another company — don't leak which case it is), AND must belong to
     * the SAME effective company as the record referencing it — visible
     * is not the same as belonging. A user authorized in both A and B can
     * see rows from either, but a record of A must not reference a
     * related record of B just because both are individually within
     * scope. company_id IS NULL on the related record is the one
     * exception: an explicit shared/global reference (Location, Route —
     * see ADR 0007), not a company mismatch.
     */
    protected function assertRelatedRecordAccessible(?int $id, string $modelClass, string $label, ?int $effectiveCompanyId): void
    {
        if ($id === null) {
            return;
        }

        $related = $modelClass::find($id);

        if (! $related) {
            throw new AuthorizationException("The related {$label} does not exist or is not accessible to your company.");
        }

        if ($related->company_id !== null && $related->company_id !== $effectiveCompanyId) {
            throw new AuthorizationException("The related {$label} belongs to a different company.");
        }
    }
}
