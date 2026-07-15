<?php

namespace Webkul\Product\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Product\Database\Factories\PackagingFactory;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\HasCompanyScope;

class Packaging extends Model implements Sortable
{
    use HasCompanyScope, HasFactory, SortableTrait;

    protected $table = 'products_packagings';

    protected $fillable = [
        'name',
        'barcode',
        'qty',
        'sort',
        'product_id',
        'company_id',
        'creator_id',
    ];

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Packaging.company_id === Product.company_id is enforced here, at the
     * model boot level, not only in PackagingController (D2, aureuserp#137
     * review): Filament's PackagingResource writes the model directly, and
     * a controller-only guard leaves that write path (and any other direct
     * create/update) free to produce a mismatched cross-company row.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($packaging) {
            $packaging->creator_id ??= Auth::id();

            static::assertCompanyMatchesProduct($packaging);

            $packaging->company_id ??= Auth::user()?->default_company_id;
        });

        static::updating(function ($packaging) {
            if ($packaging->isDirty('product_id')) {
                static::assertCompanyMatchesProduct($packaging);
            }
        });
    }

    /**
     * company_id is derived from the referenced product when omitted, and
     * any explicit value that disagrees with the product's own company is
     * rejected outright — a Packaging never belongs to a different company
     * than the Product it packages.
     */
    private static function assertCompanyMatchesProduct(self $packaging): void
    {
        if (! $packaging->product_id) {
            return;
        }

        $product = Product::withoutGlobalScope(CompanyScope::class)->find($packaging->product_id);

        if (! $product) {
            return;
        }

        if ($packaging->company_id === null) {
            $packaging->company_id = $product->company_id;

            return;
        }

        if ((int) $packaging->company_id !== (int) $product->company_id) {
            throw new AuthorizationException('A packaging must belong to the same company as its product.');
        }
    }

    protected static function newFactory(): PackagingFactory
    {
        return PackagingFactory::new();
    }
}
