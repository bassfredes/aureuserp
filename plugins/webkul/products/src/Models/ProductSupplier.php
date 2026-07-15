<?php

namespace Webkul\Product\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Partner\Models\Partner;
use Webkul\Product\Database\Factories\ProductSupplierFactory;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Models\UOM;
use Webkul\Support\Traits\HasCompanyScope;

class ProductSupplier extends Model implements Sortable
{
    use HasCompanyScope;
    use HasFactory;
    use SortableTrait;

    protected $table = 'products_product_suppliers';

    protected $fillable = [
        'sort',
        'delay',
        'product_name',
        'product_code',
        'starts_at',
        'ends_at',
        'min_qty',
        'price',
        'price_discounted',
        'discount',
        'product_id',
        'partner_id',
        'currency_id',
        'company_id',
        'creator_id',
        'uom_id',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at'   => 'date',
    ];

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UOM::class, 'uom_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * ProductSupplier.company_id === Product.company_id is enforced here, at
     * the model boot level, not only in purchases' VendorPriceListController
     * (D3, aureuserp#137 review): VendorPriceResource and ManageVendors
     * write this model directly through Filament, with independent
     * product_id/company_id selects — a controller-only guard leaves those
     * write paths free to produce a mismatched cross-company row.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($productSupplier) {
            $authUser = Auth::user();

            $productSupplier->creator_id ??= $authUser->id;

            static::assertCompanyMatchesProduct($productSupplier);

            $productSupplier->company_id ??= $authUser?->default_company_id;
        });

        static::updating(function ($productSupplier) {
            if ($productSupplier->isDirty('product_id')) {
                static::assertCompanyMatchesProduct($productSupplier);
            }
        });
    }

    /**
     * company_id is derived from the referenced product when omitted, and
     * any explicit value that disagrees with the product's own company is
     * rejected outright — a vendor price list never belongs to a different
     * company than the Product it prices.
     */
    private static function assertCompanyMatchesProduct(self $productSupplier): void
    {
        if (! $productSupplier->product_id) {
            return;
        }

        $product = Product::withoutGlobalScope(CompanyScope::class)->find($productSupplier->product_id);

        if (! $product) {
            return;
        }

        if ($productSupplier->company_id === null) {
            $productSupplier->company_id = $product->company_id;

            return;
        }

        if ((int) $productSupplier->company_id !== (int) $product->company_id) {
            throw new AuthorizationException('A vendor price list must belong to the same company as its product.');
        }
    }

    protected static function newFactory(): ProductSupplierFactory
    {
        return ProductSupplierFactory::new();
    }
}
