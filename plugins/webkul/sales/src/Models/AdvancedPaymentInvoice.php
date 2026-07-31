<?php

namespace Webkul\Sale\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Auth;
use Webkul\Sale\Database\Factories\AdvancedPaymentInvoiceFactory;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\HasStrictCompanyId;

/**
 * Strict company owner (#138 A4F): company_id is its own, not derived from
 * an Order — an AdvancedPaymentInvoice can be created before it is attached
 * to any order and is later linked to one or more Order rows via the
 * AdvancedPaymentInvoiceOrderSale pivot, so HasStrictCompanyId (same
 * pattern as Journal/PaymentTerm) governs create/update authorization and
 * immutability here, not a parent-derived scope.
 */
class AdvancedPaymentInvoice extends Model
{
    use HasCompanyScope, HasFactory, HasStrictCompanyId;

    protected $table = 'sales_advance_payment_invoices';

    protected $fillable = [
        'currency_id',
        'company_id',
        'creator_id',
        'advance_payment_method',
        'fixed_amount',
        'deduct_down_payments',
        'consolidated_billing',
        'amount',
    ];

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'sales_advance_payment_invoice_order_sales', 'advance_payment_invoice_id', 'order_id')
            ->using(AdvancedPaymentInvoiceOrderSale::class);
    }

    /**
     * creator_id defaults to the acting user but is still fillable, so an
     * explicit value must be re-checked: an actor must not be able to
     * attribute the invoice to an arbitrary or nonexistent user, nor one
     * with no membership in its own company (#138 A4F review 4827999112,
     * finding CHANGES_REQUIRED_A4F_CREATOR_WRITE_PATH — the original
     * version of this check only ran in `creating()` and silently no-opped
     * on a nonexistent id, mirrors the recruitments family's
     * assertUserBelongsToCompany pattern otherwise).
     */
    private static function assertCreatorBelongsToCompany(?int $creatorId, ?int $companyId): void
    {
        if ($creatorId === null) {
            return;
        }

        $creator = User::find($creatorId);

        if (! $creator) {
            throw new AuthorizationException('The creator does not exist.');
        }

        if ($companyId === null || ! CompanyScope::allowedCompanyIds($creator)->contains((int) $companyId)) {
            throw new AuthorizationException('The creator has no membership in this company.');
        }
    }

    protected static function boot()
    {
        parent::boot();

        // Fires after HasStrictCompanyId's own `saving` listener (registered
        // earlier via bootHasStrictCompanyId(), a distinct event) has
        // already resolved and authorized company_id, so it is safe to
        // trust here on both create and update. Same exists()-branching
        // shape as HasStrictCompanyId itself: default+authorize on create,
        // reject any change to an already-persisted value on update —
        // creator_id is corrected to be immutable after creation rather
        // than merely re-validated, since "who created this" has no
        // legitimate reason to change (#138 A4F review 4827999112: the
        // original creating()-only check let a later update(['creator_id'
        // => ...]) attribute the invoice to an arbitrary user with no
        // re-check at all).
        static::saving(function ($advancedPaymentInvoice) {
            if (! $advancedPaymentInvoice->exists) {
                $advancedPaymentInvoice->creator_id ??= Auth::id();

                static::assertCreatorBelongsToCompany($advancedPaymentInvoice->creator_id, $advancedPaymentInvoice->company_id);

                return;
            }

            $originalCreatorId = $advancedPaymentInvoice->getOriginal('creator_id');

            if ($originalCreatorId !== null && (int) $originalCreatorId !== (int) $advancedPaymentInvoice->creator_id) {
                throw new AuthorizationException("Changing this AdvancedPaymentInvoice's creator is forbidden.");
            }
        });

        // Delete authorization does not fire from HasStrictCompanyId (which
        // only guards saving) and is not fully implied by HasCompanyScope's
        // read filter alone (a forAllCompanies()/system-context caller can
        // still load a cross-company row) — re-authorize explicitly here,
        // matching the delete guard called out for this owner (#138 A4F).
        static::deleting(function ($advancedPaymentInvoice) {
            CompanyScope::assertCanWriteCompany((int) $advancedPaymentInvoice->company_id);
        });
    }

    protected static function newFactory(): AdvancedPaymentInvoiceFactory
    {
        return AdvancedPaymentInvoiceFactory::new();
    }
}
