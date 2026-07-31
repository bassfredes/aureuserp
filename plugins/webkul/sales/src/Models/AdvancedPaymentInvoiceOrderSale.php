<?php

namespace Webkul\Sale\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Webkul\Sale\Database\Factories\AdvancedPaymentInvoiceOrderSaleFactory;
use Webkul\Sale\Models\Scopes\ParentDerivedCompanyScope;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

/**
 * Pivot (not a plain Model) so AdvancedPaymentInvoice::orders()'s ->using()
 * wiring makes attach()/detach()/sync()/updateExistingPivot() go through
 * this class's own save()/delete(), not a raw query-builder insert/delete
 * that would bypass every guard below entirely (#138 A4F, same reasoning
 * as the recruitments family's custom pivots in A4E). No company_id of its
 * own — isolation and authorization both derive from the
 * AdvancedPaymentInvoice side, cross-checked against the referenced
 * Order's own company_id.
 */
class AdvancedPaymentInvoiceOrderSale extends Pivot
{
    use HasFactory, ValidatesRelatedCompanyScope;

    protected $table = 'sales_advance_payment_invoice_order_sales';

    protected $fillable = [
        'advance_payment_invoice_id',
        'order_id',
    ];

    public $timestamps = false;

    public function advancePaymentInvoice(): BelongsTo
    {
        return $this->belongsTo(AdvancedPaymentInvoice::class, 'advance_payment_invoice_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Shared by creating/deleting: resolves the invoice's company
     * (authorizing the acting user for it in the process, via
     * resolveEffectiveCompanyIdOrFail()) and cross-checks the referenced
     * Order belongs to that exact same company.
     */
    private static function assertSameCompany(self $pivot): void
    {
        $companyId = static::resolveEffectiveCompanyIdOrFail($pivot->advance_payment_invoice_id, AdvancedPaymentInvoice::class, null, 'Advance Payment Invoice');

        static::assertRelatedBelongsToCompany($pivot->order_id, Order::class, 'Order', $companyId);
    }

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope(new ParentDerivedCompanyScope('advancePaymentInvoice'));

        static::creating(function (self $pivot) {
            static::assertSameCompany($pivot);
        });

        // stage_id/job_id-equivalent retargeting gap (#138 PR4 review
        // 4818602853, finding 2, closed for recruitments in A4E): both keys
        // are fillable and creating()/deleting() alone never re-check a
        // retarget of an already-persisted row. Only detach+attach may
        // change either.
        static::updating(function (self $pivot) {
            if ($pivot->isDirty(['advance_payment_invoice_id', 'order_id'])) {
                throw new AuthorizationException('Retargeting an AdvancedPaymentInvoiceOrderSale is forbidden — detach and attach instead.');
            }
        });

        static::deleting(function (self $pivot) {
            static::assertSameCompany($pivot);
        });
    }

    protected static function newFactory(): AdvancedPaymentInvoiceOrderSaleFactory
    {
        return AdvancedPaymentInvoiceOrderSaleFactory::new();
    }
}
