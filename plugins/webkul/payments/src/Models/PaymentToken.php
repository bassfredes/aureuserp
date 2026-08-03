<?php

namespace Webkul\Payment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Webkul\Payment\Database\Factories\PaymentTokenFactory;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\HasStrictCompanyId;

/**
 * strict_company, no shared rows: a stored card/payment-method token is
 * always tied to a single company's gateway credentials, unlike
 * CurrencyRate's company_or_shared pattern (#138 PR4 A4J).
 */
class PaymentToken extends Model
{
    use HasCompanyScope, HasFactory, HasStrictCompanyId;

    protected $table = 'payments_payment_tokens';

    protected $fillable = [
        'company_id',
        'payment_method_id',
        'partner_id',
        'created_by',
        'payment_details',
        'provider_reference_id',
        'is_active',
    ];

    protected $casts = [
        'payment_details' => 'array',
        'is_active'       => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        // HasStrictCompanyId only guards saving (create/update); delete
        // needs its own re-authorization of the persisted company, same
        // as OrderTemplate (#138 PR4 A4H) — HasCompanyScope's read filter
        // alone does not cover a row loaded via forAllCompanies() or a
        // system context bypass.
        static::deleting(function (self $paymentToken) {
            CompanyScope::assertCanWriteCompany((int) $paymentToken->company_id);
        });
    }

    protected static function newFactory(): PaymentTokenFactory
    {
        return PaymentTokenFactory::new();
    }
}
