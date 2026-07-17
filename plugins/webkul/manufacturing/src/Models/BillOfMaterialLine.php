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
 * user's default) — a line's company must always match its BOM, including
 * a null (global template) one, so it inherits the same
 * IncludesSharedCompanyRows visibility (ADR 0007).
 */
class BillOfMaterialLine extends Model implements IncludesSharedCompanyRows
{
    use HasCompanyScope, HasFactory, ValidatesRelatedCompanyScope;

    protected $table = 'manufacturing_bill_of_material_lines';

    protected $fillable = [
        'sort',
        'quantity',
        'is_manual_consumption',
        'bill_of_material_id',
        'product_id',
        'company_id',
        'uom_id',
        'operation_id',
        'creator_id',
    ];

    protected $casts = [
        'quantity'              => 'decimal:4',
        'is_manual_consumption' => 'boolean',
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
        return $this->belongsToMany(ProductAttributeValue::class, 'manufacturing_bill_of_material_line_attribute_values', 'bill_of_material_line_id', 'product_attribute_value_id');
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $line): void {
            $line->creator_id ??= Auth::id();
        });

        static::saving(function (self $line): void {
            // Always synced from the parent BOM, never independently
            // defaulted from the acting user (a line's company can never
            // diverge from its BillOfMaterial's, including a null/global
            // one).
            $line->company_id = $line->billOfMaterial?->company_id;

            // A specific-company line referencing a Product from a
            // different company is a relation-integrity gap read isolation
            // alone doesn't cover (#138, D5b pattern, aureuserp#137). A
            // null-company (global template) line has no company to check
            // the Product against.
            if ($line->company_id !== null) {
                static::assertRelatedBelongsToCompany($line->product_id, Product::class, 'Product', $line->company_id);
            }
        });
    }

    public function skipBomLine($product)
    {
        return false;
    }
}
