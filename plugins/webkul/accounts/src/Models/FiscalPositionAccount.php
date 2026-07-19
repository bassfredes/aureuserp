<?php

namespace Webkul\Account\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

class FiscalPositionAccount extends Model
{
    use HasCompanyScope, HasFactory, ValidatesRelatedCompanyScope;

    protected $table = 'accounts_fiscal_position_accounts';

    protected $fillable = [
        'fiscal_position_id',
        'company_id',
        'account_source_id',
        'account_destination_id',
        'creator_id',
    ];

    public function fiscalPosition()
    {
        return $this->belongsTo(FiscalPosition::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function accountSource()
    {
        return $this->belongsTo(Account::class, 'account_source_id');
    }

    public function accountDestination()
    {
        return $this->belongsTo(Account::class, 'account_destination_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($fiscalPositionAccount) {
            $fiscalPositionAccount->creator_id ??= Auth::id();
        });

        static::saving(function ($fiscalPositionAccount) {
            // Always re-derived from the parent FiscalPosition
            // (strict_company, never null) — a missing FiscalPosition or a
            // fiscal_position_id reassignment that resolves to a different
            // company than this row's current one is rejected outright,
            // not silently moved (#138 review, 2026-07-18).
            $fiscalPositionAccount->company_id = static::resolveEffectiveCompanyIdOrFail(
                $fiscalPositionAccount->fiscal_position_id,
                FiscalPosition::class,
                $fiscalPositionAccount->company_id,
                'Fiscal Position'
            );

            // Account has no company_id of its own — it must instead be
            // explicitly enabled for this company via the
            // accounts_account_companies pivot.
            Account::assertEnabledForCompany($fiscalPositionAccount->account_source_id, $fiscalPositionAccount->company_id, 'Account Source');
            Account::assertEnabledForCompany($fiscalPositionAccount->account_destination_id, $fiscalPositionAccount->company_id, 'Account Destination');
        });
    }
}
