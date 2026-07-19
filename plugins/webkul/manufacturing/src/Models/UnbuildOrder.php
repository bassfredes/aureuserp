<?php

namespace Webkul\Manufacturing\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Inventory\Models\Location;
use Webkul\Manufacturing\Database\Factories\UnbuildOrderFactory;
use Webkul\Manufacturing\Enums\UnbuildOrderState;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Models\UOM;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

class UnbuildOrder extends Model
{
    use HasCompanyScope, HasFactory, ValidatesRelatedCompanyScope;

    protected $table = 'manufacturing_unbuild_orders';

    protected $fillable = [
        'name',
        'state',
        'quantity',
        'product_id',
        'company_id',
        'uom_id',
        'bill_of_material_id',
        'manufacturing_order_id',
        'lot_id',
        'location_id',
        'destination_location_id',
        'creator_id',
    ];

    protected $casts = [
        'state'    => UnbuildOrderState::class,
        'quantity' => 'decimal:4',
    ];

    public function getModelTitle(): string
    {
        return __('manufacturing::models/unbuild-order.title');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UOM::class)->withTrashed();
    }

    public function billOfMaterial(): BelongsTo
    {
        return $this->belongsTo(BillOfMaterial::class, 'bill_of_material_id')->withTrashed();
    }

    public function manufacturingOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'manufacturing_order_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class, 'lot_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id')->withTrashed();
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): UnbuildOrderFactory
    {
        return UnbuildOrderFactory::new();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $unbuildOrder): void {
            $authUser = Auth::user();

            $unbuildOrder->creator_id ??= $authUser?->id;
            $unbuildOrder->state ??= UnbuildOrderState::DRAFT;

            // company_id itself is resolved and authorized in the earlier-
            // firing `saving` listener below (#138 review round 3,
            // 2026-07-18).
        });

        static::saving(function (self $unbuildOrder): void {
            // Standalone strict owner: resolve + authorize on create,
            // enforce immutability + re-authorize on every update — not
            // only when company_id itself changed. Checked first, before
            // relation-integrity validation below, so a rejected company
            // change never partially validates product/BOM/order
            // consistency against the attempted new company, and so an
            // actor who obtained a cross-company row via an unscoped query
            // can't slip an unrelated-field update through (#138 review
            // round 2 + round 3, 2026-07-18).
            if (! $unbuildOrder->exists) {
                $unbuildOrder->company_id ??= Auth::user()?->default_company_id;

                if ($unbuildOrder->company_id === null) {
                    throw new AuthorizationException('UnbuildOrder requires a company_id and none could be resolved from the acting user.');
                }

                CompanyScope::assertCanWriteCompany((int) $unbuildOrder->company_id);
            } else {
                $originalCompanyId = $unbuildOrder->getOriginal('company_id');

                if ($originalCompanyId !== null && (int) $originalCompanyId !== (int) $unbuildOrder->company_id) {
                    throw new AuthorizationException('Changing the company of this record is forbidden — archive it and create a new one instead.');
                }

                CompanyScope::assertCanWriteCompany((int) ($originalCompanyId ?? $unbuildOrder->company_id));
            }

            // Same relation-integrity gap closed for Order/MoveLine/BOM
            // (#138, D5b pattern, aureuserp#137): read isolation alone
            // doesn't stop a user in company A+B from pointing an unbuild
            // order in A at a Product, BillOfMaterial, or manufacturing
            // Order from B. BillOfMaterial is strict_company (D2) — always
            // non-null — so this is unconditional; checking
            // `$unbuildOrder->billOfMaterial?->company_id` instead would
            // use the scoped relation, which resolves to null (skipping
            // the guard) whenever the acting user simply can't see the
            // referenced BOM — the exact bypass this guard exists to close
            // (#138 review, 2026-07-18).
            if ($unbuildOrder->isDirty(['product_id', 'company_id'])) {
                static::assertRelatedBelongsToCompany($unbuildOrder->product_id, Product::class, 'Product', $unbuildOrder->company_id);
            }

            if ($unbuildOrder->isDirty(['bill_of_material_id', 'company_id'])) {
                static::assertRelatedBelongsToCompany($unbuildOrder->bill_of_material_id, BillOfMaterial::class, 'Bill Of Material', $unbuildOrder->company_id);
            }

            if ($unbuildOrder->isDirty(['manufacturing_order_id', 'company_id'])) {
                static::assertRelatedBelongsToCompany($unbuildOrder->manufacturing_order_id, Order::class, 'Manufacturing Order', $unbuildOrder->company_id);
            }
        });
    }
}
