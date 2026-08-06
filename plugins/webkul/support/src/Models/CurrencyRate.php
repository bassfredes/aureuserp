<?php

namespace Webkul\Support\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Security\Models\User;
use Webkul\Support\Database\Factories\CurrencyRateFactory;
use Webkul\Support\Enums\CompanyContextMode;
use Webkul\Support\Models\Contracts\IncludesSharedCompanyRows;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;
use Webkul\Support\Traits\HasCompanyScope;

/**
 * company_id IS NULL rows are system-managed shared exchange rates usable
 * across every company, not incomplete records — same treatment as
 * Route/Location/ProjectStage/ActivityPlan (ADR 0007, company_or_shared).
 * No SoftDeletes on this table (#138 PR4 A4D-0): delete() is a hard delete,
 * there is no restore()/forceDelete() distinction to guard.
 */
class CurrencyRate extends Model implements IncludesSharedCompanyRows
{
    use HasCompanyScope, HasFactory;

    protected $fillable = [
        'name',
        'rate',
        'currency_id',
        'creator_id',
        'company_id',
        'created_at',
    ];

    protected $casts = [
        'name' => 'date',
        'rate' => 'decimal:6',
    ];

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function getInverseRateAttribute()
    {
        if ($this->rate == 0) {
            return null;
        }

        return 1 / $this->rate;
    }

    /**
     * Mirrors ProjectStage/ActivityPlan::guardSharedRowMutation(): blocks
     * any authenticated non-super_admin from creating, updating or deleting
     * a shared (company_id null) row. Unlike those two precedents, a
     * no-user caller must ALSO be inside an explicit ALL_COMPANIES/BOOTSTRAP
     * system context to mutate a shared row here — CurrencyRate carries
     * financial data, so an absent context (no user, no CompanyContext at
     * all) is rejected rather than treated as an unrestricted system
     * process (#138 PR4 A4D-0, stricter than the precedent by explicit
     * instruction).
     */
    protected static function guardSharedRowMutation(bool $isNullCompany): void
    {
        if (! $isNullCompany) {
            return;
        }

        if (static::actingUserIsSuperAdmin()) {
            return;
        }

        if (! Auth::check()) {
            $context = CompanyContext::current();

            if ($context?->mode === CompanyContextMode::ALL_COMPANIES || $context?->mode === CompanyContextMode::BOOTSTRAP) {
                return;
            }
        }

        throw new AuthorizationException('Shared CurrencyRate records (company_id is null) can only be created or modified by a super_admin or an explicit system process.');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $currencyRate) {
            $currencyRate->creator_id ??= Auth::id();

            // Unlike ProjectStage/ActivityPlan, company_id is never
            // defaulted from the acting user here: this model's contract
            // requires company_id = null to mean a deliberate, super_admin
            // -only shared rate (#138 PR4 A4D-0) — silently coalescing a
            // regular actor's null into their own company would let that
            // actor create a rate without ever proving they hold an
            // explicit, authorized company_id, and would mask a super_admin
            // 's genuine intent to create a shared row behind the same
            // default. Callers must always pass an explicit company_id (or
            // explicit null, if authorized).
            static::guardSharedRowMutation($currencyRate->company_id === null);

            // An explicit company_id must still pass authorization — a user
            // of A knowing B's id is not enough to create a rate directly
            // in B (#138 PR4 A4D-0, same guarantee ProjectStage/ActivityPlan
            // provide).
            if ($currencyRate->company_id !== null) {
                CompanyScope::assertCanWriteCompany((int) $currencyRate->company_id);
            }
        });

        static::updating(function (self $currencyRate) {
            $originalCompanyId = $currencyRate->getOriginal('company_id');

            static::guardSharedRowMutation($originalCompanyId === null);

            if ($currencyRate->isDirty('company_id')) {
                throw new AuthorizationException('Changing the company of this CurrencyRate is forbidden — archive it and create a new one instead.');
            }

            if ($originalCompanyId !== null) {
                CompanyScope::assertCanWriteCompany((int) $originalCompanyId);
            }
        });

        static::deleting(function (self $currencyRate) {
            $companyId = $currencyRate->getOriginal('company_id');

            static::guardSharedRowMutation($companyId === null);

            if ($companyId !== null) {
                CompanyScope::assertCanWriteCompany((int) $companyId);
            }
        });
    }

    protected static function newFactory(): CurrencyRateFactory
    {
        return CurrencyRateFactory::new();
    }
}
