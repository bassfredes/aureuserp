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
 * BillOfMaterial (itself always non-null, never the acting user's
 * default) — a line's company can never diverge from its BOM's.
 */
class BillOfMaterialLine extends Model
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
            // Always re-derived from the parent BOM (never independently
            // defaulted from the acting user, never left to drift): a
            // missing/no-company BOM is a hard failure, and a BOM
            // reassignment that resolves to a different company than this
            // line's current one is rejected outright, not silently moved
            // (#138 review, 2026-07-18).
            $line->company_id = static::resolveEffectiveCompanyIdOrFail(
                $line->bill_of_material_id,
                BillOfMaterial::class,
                $line->company_id,
                'Bill Of Material'
            );

            // A line referencing a Product from a different company than
            // its BOM is a relation-integrity gap read isolation alone
            // doesn't cover (#138, D5b pattern, aureuserp#137).
            static::assertRelatedBelongsToCompany($line->product_id, Product::class, 'Product', $line->company_id);

            // operation_id must belong to THIS line's own BOM, not merely
            // to a BOM in the same company — two BOMs of the same company
            // each define their own Operations, and this line's Operation
            // must be one of its own BOM's (#138 review round 2,
            // 2026-07-18).
            if ($line->operation_id) {
                $operation = Operation::withTrashed()->find($line->operation_id);

                if (! $operation || (int) $operation->bill_of_material_id !== (int) $line->bill_of_material_id) {
                    throw new AuthorizationException('The related Operation does not belong to this Bill Of Material.');
                }
            }
        });
    }

    public function skipBomLine($product)
    {
        return false;
    }
}
