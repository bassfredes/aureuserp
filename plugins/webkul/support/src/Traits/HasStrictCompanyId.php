<?php

namespace Webkul\Support\Traits;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

/**
 * For strict_company owners with no parent to derive company_id from
 * (Journal, PaymentTerm, FiscalPosition, Reconcile, ...) — pure top-level
 * entities the acting user creates directly, unlike MoveLine/BillOfMaterial
 * which derive from Move/Product. Defaults company_id from the acting
 * user's own default_company_id at create time as a convenience, then
 * fails closed if it's still null: never persist a company-less strict_company
 * row. Once persisted, company_id can never change on update — matches the
 * existing Route/Location "archive and recreate instead" precedent
 * (aureuserp#137) — since #138's review found several standalone owners
 * carrying HasCompanyScope with no equivalent model-level guarantee at all
 * (2026-07-18).
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
        });

        static::updating(function ($model): void {
            if ($model->isDirty('company_id')) {
                throw new AuthorizationException('Changing the company of this record is forbidden — archive it and create a new one instead.');
            }
        });
    }
}
