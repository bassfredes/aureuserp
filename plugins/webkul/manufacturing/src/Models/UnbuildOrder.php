<?php

namespace Webkul\Manufacturing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Inventory\Models\Location;
use Webkul\Manufacturing\Database\Factories\UnbuildOrderFactory;
use Webkul\Manufacturing\Enums\UnbuildOrderState;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
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
            $unbuildOrder->company_id ??= $authUser?->default_company_id;
            $unbuildOrder->state ??= UnbuildOrderState::DRAFT;
        });

        static::saving(function (self $unbuildOrder): void {
            // Same relation-integrity gap closed for Order/MoveLine/BOM
            // (#138, D5b pattern, aureuserp#137): read isolation alone
            // doesn't stop a user in company A+B from pointing an unbuild
            // order in A at a Product, BillOfMaterial, or manufacturing
            // Order from B. A null-company BOM is a global template (ADR
            // 0007) and has no company to check against.
            if ($unbuildOrder->isDirty(['product_id', 'company_id'])) {
                static::assertRelatedBelongsToCompany($unbuildOrder->product_id, Product::class, 'Product', $unbuildOrder->company_id);
            }

            if ($unbuildOrder->bill_of_material_id && $unbuildOrder->isDirty(['bill_of_material_id', 'company_id'])) {
                if ($unbuildOrder->billOfMaterial?->company_id !== null) {
                    static::assertRelatedBelongsToCompany($unbuildOrder->bill_of_material_id, BillOfMaterial::class, 'Bill Of Material', $unbuildOrder->company_id);
                }
            }

            if ($unbuildOrder->manufacturing_order_id && $unbuildOrder->isDirty(['manufacturing_order_id', 'company_id'])) {
                static::assertRelatedBelongsToCompany($unbuildOrder->manufacturing_order_id, Order::class, 'Manufacturing Order', $unbuildOrder->company_id);
            }
        });
    }
}
