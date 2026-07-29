<?php

namespace Webkul\Employee\Models\Concerns;

use Webkul\Support\Models\Scopes\CompanyScope;

/**
 * HasStrictCompanyId only guards create/update (a single `saving`
 * listener) — delete/restore/forceDelete need the same "reauthorize the
 * persisted company_id, resolve from getOriginal(), never trust an
 * in-memory value" guarantee. Deliberately not added to HasStrictCompanyId
 * itself (a repo-wide trait change is out of scope for this wave) — a
 * small, local concern shared by the 4 strict_company owners in the
 * employees family that all use SoftDeletes (#138 PR4 A4D).
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
     * Resolves the persisted company_id (getOriginal(), never the
     * in-memory attribute) so an actor cannot bypass authorization by
     * mutating company_id in memory before calling delete()/restore()/
     * forceDelete(). A null persisted company_id is out of scope for this
     * wave (these 4 owners are strict_company, not company_or_shared) and
     * is left unauthorized-but-unblocked here, matching HasStrictCompanyId's
     * own update-time behavior of only comparing when the original is set.
     */
    protected static function assertCanMutateLifecycleCompany($model): void
    {
        $companyId = $model->getOriginal('company_id');

        if ($companyId !== null) {
            CompanyScope::assertCanWriteCompany((int) $companyId);
        }
    }
}
