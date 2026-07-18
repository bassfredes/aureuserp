<?php

namespace Webkul\Support\Traits;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Webkul\Support\Models\Scopes\CompanyScope;

/**
 * For strict_company owners with no parent to derive company_id from
 * (Journal, PaymentTerm, FiscalPosition, Reconcile, ...) — pure top-level
 * entities the acting user creates directly, unlike MoveLine/BillOfMaterial
 * which derive from Move/Product. Defaults company_id from the acting
 * user's own default_company_id at create time as a convenience, then
 * fails closed if it's still null: never persist a company-less strict_company
 * row. An explicit company_id (not the user's own default) must still pass
 * CompanyScope::assertCanWriteCompany() — a user of A knowing B's id is not
 * enough to create a row directly in B (#138 review round 2, 2026-07-18).
 * Once persisted, company_id can never change on update — matches the
 * existing Route/Location "archive and recreate instead" precedent
 * (aureuserp#137) — since #138's review found several standalone owners
 * carrying HasCompanyScope with no equivalent model-level guarantee at all.
 *
 * Everything — create-time default/authorize, update-time immutability, AND
 * update-time re-authorization of the unchanged company — runs from a
 * single `saving` listener (fires before both `creating` and `updating` in
 * Eloquent's save() pipeline). Two independent reasons this is one listener,
 * not split across `creating`/`updating`:
 *
 * 1. A model's own `saving` hook can run side effects (e.g. Journal
 *    attaching Accounts to a company via ensureEnabledForCompany()) before
 *    an `updating`-level rejection ever fires — registering here, inside
 *    bootHasStrictCompanyId() (called from parent::boot() before a model's
 *    own boot() body registers further listeners), guarantees this runs
 *    first among same-named listeners (#138 review round 2, 2026-07-18).
 * 2. Read isolation is not the same guarantee as write authorization: an
 *    actor who obtains a cross-company row via an unscoped query (or any
 *    other bypass) and updates a field that has nothing to do with
 *    company_id — name, a status flag, a date — must still be rejected.
 *    Gating authorization on `creating` only, or on `isDirty('company_id')`
 *    only, misses exactly that case; every save must re-authorize the
 *    row's effective company, changed or not (#138 review round 3,
 *    2026-07-18).
 */
trait HasStrictCompanyId
{
    public static function bootHasStrictCompanyId(): void
    {
        static::saving(function ($model): void {
            if (! $model->exists) {
                $model->company_id ??= Auth::user()?->default_company_id;

                if ($model->company_id === null) {
                    throw new AuthorizationException(static::class.' requires a company_id and none could be resolved from the acting user.');
                }

                CompanyScope::assertCanWriteCompany((int) $model->company_id);

                return;
            }

            // getOriginal(), not isDirty(): a nested save triggered from
            // within this same model's own `created` hook sees exists=true
            // but Eloquent hasn't synced $original yet at that point —
            // every attribute reads as "dirty" relative to an empty
            // original, producing a false positive. Comparing against
            // getOriginal() directly (and skipping when it's still
            // unset/null, i.e. this very moment) is immune to that
            // ordering quirk while still catching a genuine company_id
            // change on an already-synced record (#138 review round 2,
            // 2026-07-18).
            $originalCompanyId = $model->getOriginal('company_id');

            if ($originalCompanyId !== null && (int) $originalCompanyId !== (int) $model->company_id) {
                throw new AuthorizationException('Changing the company of this record is forbidden — archive it and create a new one instead.');
            }

            // Re-authorize the row's effective company on every update,
            // not only when company_id itself changed (#138 review round
            // 3, 2026-07-18) — see rationale #2 above. Falls back to the
            // in-memory company_id only for the nested-save case where
            // $originalCompanyId is still unset.
            CompanyScope::assertCanWriteCompany((int) ($originalCompanyId ?? $model->company_id));
        });
    }
}
