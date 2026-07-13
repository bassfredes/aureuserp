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
     */
    protected function assertCompanyIdImmutable(?int $submittedCompanyId, Model $model, string $label = 'record'): void
    {
        if ($submittedCompanyId === null) {
            return;
        }

        if ($submittedCompanyId !== $model->company_id) {
            throw new AuthorizationException("Changing the company of this {$label} is forbidden at this point, you should rather archive it and create a new one.");
        }
    }

    /**
     * A related tenant-scoped id must resolve through the scoped model
     * lookup, not FormRequest's exists:table,id. Model::find() returns
     * null both for a genuinely missing id and for one belonging to
     * another company — don't leak which case it is.
     */
    protected function assertRelatedRecordAccessible(?int $id, string $modelClass, string $label): void
    {
        if ($id === null) {
            return;
        }

        if (! $modelClass::find($id)) {
            throw new AuthorizationException("The related {$label} does not exist or is not accessible to your company.");
        }
    }
}
