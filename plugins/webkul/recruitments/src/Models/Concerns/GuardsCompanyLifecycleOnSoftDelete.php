<?php

namespace Webkul\Recruitment\Models\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Support\Models\Scopes\CompanyScope;

/**
 * HasStrictCompanyId only guards create/update (a single `saving`
 * listener) — delete/restore/forceDelete need the same "reauthorize the
 * persisted company_id, never trust an in-memory value" guarantee. Local
 * to recruitments (a repo-wide trait change is out of scope) — same
 * design as the employees family's own copy (#138 PR4 A4D), applied here
 * to Applicant/Candidate (#138 PR4 A4E).
 */
trait GuardsCompanyLifecycleOnSoftDelete
{
    public static function bootGuardsCompanyLifecycleOnSoftDelete(): void
    {
        static::deleting(function ($model): void {
            static::assertCanMutateLifecycleCompany($model);
        });

        static::restoring(function ($model): void {
            static::assertCanMutateLifecycleCompany($model);
        });

        static::forceDeleting(function ($model): void {
            static::assertCanMutateLifecycleCompany($model);
        });
    }

    /**
     * Re-queries company_id fresh by primary key, bypassing the model's
     * own CompanyScope AND any soft-delete scope (deleting/forceDeleting
     * can run on an already-trashed row, restoring always does) — never
     * getOriginal() and never the in-memory attribute, since a row
     * fetched via a partial column projection never populates company_id
     * at all (#138 PR4 A4D review 4811425870, finding 1).
     *
     * An unresolvable owner (no primary key, row not found, or a
     * persisted company_id of null) must reject the lifecycle mutation
     * rather than silently let it through as "unauthorized-but-unblocked"
     * (#138 PR4 A4D review 4811942781,
     * CHANGES_REQUIRED_A4D_EMPLOYEES_FAIL_CLOSED_OWNER_RESOLUTION) —
     * applied here from the start rather than in a later correction.
     */
    protected static function assertCanMutateLifecycleCompany($model): void
    {
        $key = $model->getKey();

        if ($key === null) {
            throw new AuthorizationException('Cannot mutate the lifecycle of a model with no primary key.');
        }

        $companyId = static::withTrashed()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereKey($key)
            ->value('company_id');

        if ($companyId === null) {
            throw new AuthorizationException('Cannot mutate the lifecycle of a row whose company could not be resolved.');
        }

        CompanyScope::assertCanWriteCompany((int) $companyId);
    }
}
