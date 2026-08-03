<?php

namespace Webkul\Payment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Webkul\Payment\Database\Factories\PaymentTransactionFactory;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\HasStrictCompanyId;

/**
 * strict_company, no shared rows: a gateway transaction always belongs to
 * the company whose journal entry it settles (#138 PR4 A4J).
 */
class PaymentTransaction extends Model
{
    use HasCompanyScope, HasFactory, HasStrictCompanyId;

    protected $table = 'payments_payment_transactions';

    protected $fillable = [
        'sort',
        'move_id',
        'journal_id',
        'company_id',
        'statement_id',
        'partner_id',
        'currency_id',
        'foreign_currency_id',
        'creator_id',
        'account_number',
        'partner_name',
        'transaction_type',
        'payment_reference',
        'internal_index',
        'transaction_details',
        'amount',
        'amount_currency',
        'amount_residual',
        'is_reconciled',
    ];

    protected $casts = [
        'transaction_details' => 'array',
        'is_reconciled'       => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        // HasStrictCompanyId only guards saving (create/update); delete
        // needs its own re-authorization of the persisted company, same
        // as OrderTemplate (#138 PR4 A4H) — HasCompanyScope's read filter
        // alone does not cover a row loaded via forAllCompanies() or a
        // system context bypass.
        static::deleting(function (self $paymentTransaction) {
            CompanyScope::assertCanWriteCompany((int) $paymentTransaction->company_id);
        });
    }

    protected static function newFactory(): PaymentTransactionFactory
    {
        return PaymentTransactionFactory::new();
    }
}
