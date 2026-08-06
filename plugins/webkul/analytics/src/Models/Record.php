<?php

namespace Webkul\Analytic\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\HasCompanyScope;

/**
 * Physical owner of the `analytic_records` table — Webkul\Project\Models\
 * Timesheet and Webkul\Timesheet\Models\Timesheet are both aliases over
 * this exact table (no discriminator column enforced at the DB level).
 * HasCompanyScope and the write-authorization hook live HERE, not only on
 * the aliases, so a query or write against Record directly can never
 * bypass isolation that only the aliases enforced (#138 PR4 ola4A round 2
 * review).
 */
class Record extends Model
{
    use HasCompanyScope;

    protected $table = 'analytic_records';

    protected $fillable = [
        'type',
        'name',
        'date',
        'amount',
        'unit_amount',
        'partner_id',
        'company_id',
        'user_id',
        'creator_id',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($record) {
            $record->creator_id ??= Auth::id();
        });

        static::saving(function (self $record): void {
            $effectiveCompanyId = $record->resolveEffectiveCompanyId();

            $originalCompanyId = $record->exists ? $record->getOriginal('company_id') : null;

            if ($originalCompanyId !== null && (int) $originalCompanyId !== (int) $effectiveCompanyId) {
                throw new AuthorizationException('Changing the company of this '.static::class.' is forbidden — archive it and create a new one instead.');
            }

            $record->company_id = $effectiveCompanyId;
        });
    }

    /**
     * Polymorphic company resolution. This base implementation covers a
     * standalone Record with no richer parent graph: keep the existing/
     * explicit company_id, or default it from the acting user, then
     * authorize. Webkul\Project\Models\Timesheet overrides this to derive
     * (and cross-validate) Task → Project instead (#138 PR4 ola4A round 2
     * review).
     */
    protected function resolveEffectiveCompanyId(): int
    {
        $companyId = $this->company_id ?? Auth::user()?->default_company_id;

        if ($companyId === null) {
            throw new AuthorizationException(static::class.' requires a company_id and none could be resolved from the acting user.');
        }

        CompanyScope::assertCanWriteCompany((int) $companyId);

        return (int) $companyId;
    }
}
