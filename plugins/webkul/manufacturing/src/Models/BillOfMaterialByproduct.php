<?php

namespace Webkul\Manufacturing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Auth;
use Webkul\Product\Models\ProductAttributeValue;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Contracts\IncludesSharedCompanyRows;
use Webkul\Support\Models\UOM;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

/**
 * Derives company_id from its parent BillOfMaterial (never the acting
 * user's default) — same reasoning as BillOfMaterialLine.
 */
class BillOfMaterialByproduct extends Model implements IncludesSharedCompanyRows
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
            $byproduct->company_id = $byproduct->billOfMaterial?->company_id;

            if ($byproduct->company_id !== null) {
                static::assertRelatedBelongsToCompany($byproduct->product_id, Product::class, 'Product', $byproduct->company_id);
            }
        });
    }
}
