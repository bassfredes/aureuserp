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
 * The immutability guard runs on `saving` (fires before both `creating` and
 * `updating` in Eloquent's save() pipeline), not `updating` — a model's own
 * `saving` hook can otherwise run side effects (e.g. Journal attaching
 * Accounts to a new company via ensureEnabledForCompany()) before an
 * `updating`-level rejection ever fires, leaving those side effects
 * persisted despite the update itself being rejected (#138 review round 2,
 * 2026-07-18). Registering here, inside bootHasStrictCompanyId() (called
 * from parent::boot() before a model's own boot() body registers further
 * listeners), guarantees this fires first among same-named listeners.
 */
trait HasStrictCompanyId
{
    public static function bootHasStrictCompanyId(): void
    {
        static::creating(function ($model): void {
            $model->company_id ??= Auth::user()?->default_company_id;

            if ($model->company_id === null) {
                throw new AuthorizationException(static::class.' requires a company_id and none could be resolved from the acting user.');
            }

            CompanyScope::assertCanWriteCompany((int) $model->company_id);
        });

        static::saving(function ($model): void {
            if (! $model->exists) {
                return;
            }

            // getOriginal(), not isDirty(): a nested save triggered from
            // within this same model's own `created` hook (e.g. Order's
            // `$order->update([...])` follow-up) sees exists=true but
            // Eloquent hasn't synced $original yet at that point — every
            // attribute reads as "dirty" relative to an empty original,
            // producing a false positive. Comparing against getOriginal()
            // directly (and skipping when it's still unset/null) is
            // immune to that ordering quirk while still catching a
            // genuine company_id change on an already-synced record
            // (#138 review round 2, 2026-07-18).
            $originalCompanyId = $model->getOriginal('company_id');

            if ($originalCompanyId !== null && (int) $originalCompanyId !== (int) $model->company_id) {
                throw new AuthorizationException('Changing the company of this record is forbidden — archive it and create a new one instead.');
            }
        });
    }
}
