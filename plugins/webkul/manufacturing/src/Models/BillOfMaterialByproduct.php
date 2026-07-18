<?php

namespace Webkul\Manufacturing\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Auth;
use Webkul\Product\Models\ProductAttributeValue;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\UOM;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

/**
 * strict_company (D2): company_id is always derived from the parent
 * BillOfMaterial (never the acting user's default) — same reasoning as
 * BillOfMaterialLine.
 */
class BillOfMaterialByproduct extends Model
{
    use HasCompanyScope, HasFactory, ValidatesRelatedCompanyScope;

    protected $table = 'manufacturing_bill_of_material_byproducts';

    protected $fillable = [
        'sort',
        'quantity',
        'cost_share',
        'bill_of_material_id',
        'product_id',
        'company_id',
        'uom_id',
        'operation_id',
        'creator_id',
    ];

    protected $casts = [
        'quantity'   => 'decimal:4',
        'cost_share' => 'decimal:2',
    ];

    public function billOfMaterial(): BelongsTo
    {
        return $this->belongsTo(BillOfMaterial::class, 'bill_of_material_id')->withTrashed();
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

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class, 'operation_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(ProductAttributeValue::class, 'manufacturing_bill_of_material_byproduct_attribute_values', 'byproduct_id', 'product_attribute_value_id');
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $byproduct): void {
            $byproduct->creator_id ??= Auth::id();
        });

        static::saving(function (self $byproduct): void {
            // Always re-derived from the parent BOM — see
            // BillOfMaterialLine's identical saving() hook for the full
            // rationale (#138 review, 2026-07-18).
            $byproduct->company_id = static::resolveEffectiveCompanyIdOrFail(
                $byproduct->bill_of_material_id,
                BillOfMaterial::class,
                $byproduct->company_id,
                'Bill Of Material'
            );

            static::assertRelatedBelongsToCompany($byproduct->product_id, Product::class, 'Product', $byproduct->company_id);

            // operation_id must belong to THIS byproduct's own BOM — see
            // BillOfMaterialLine's identical check for the full rationale
            // (#138 review round 2, 2026-07-18).
            if ($byproduct->operation_id) {
                $operation = Operation::withTrashed()->find($byproduct->operation_id);

                if (! $operation || (int) $operation->bill_of_material_id !== (int) $byproduct->bill_of_material_id) {
                    throw new AuthorizationException('The related Operation does not belong to this Bill Of Material.');
                }
            }
        });
    }
}
