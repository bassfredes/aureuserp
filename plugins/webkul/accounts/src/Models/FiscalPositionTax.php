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

class FiscalPositionTax extends Model
{
    use HasCompanyScope, HasFactory, ValidatesRelatedCompanyScope;

    protected $table = 'accounts_fiscal_position_taxes';

    protected $fillable = [
        'fiscal_position_id',
        'company_id',
        'tax_source_id',
        'tax_destination_id',
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

    public function taxSource()
    {
        return $this->belongsTo(Tax::class, 'tax_source_id');
    }

    public function taxDestination()
    {
        return $this->belongsTo(Tax::class, 'tax_destination_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($fiscalPositionTax) {
            $fiscalPositionTax->creator_id ??= Auth::id();
        });

        static::saving(function ($fiscalPositionTax) {
            // Always re-derived from the parent FiscalPosition
            // (strict_company, never null) — see FiscalPositionAccount's
            // identical saving() hook for the full rationale (#138 review,
            // 2026-07-18).
            $fiscalPositionTax->company_id = static::resolveEffectiveCompanyIdOrFail(
                $fiscalPositionTax->fiscal_position_id,
                FiscalPosition::class,
                $fiscalPositionTax->company_id,
                'Fiscal Position'
            );

            static::assertRelatedBelongsToCompany($fiscalPositionTax->tax_source_id, Tax::class, 'Tax Source', $fiscalPositionTax->company_id);
            static::assertRelatedBelongsToCompany($fiscalPositionTax->tax_destination_id, Tax::class, 'Tax Destination', $fiscalPositionTax->company_id);
        });
    }
}
