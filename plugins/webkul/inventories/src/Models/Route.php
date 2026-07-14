<?php

namespace Webkul\Inventory\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Inventory\Database\Factories\RouteFactory;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Contracts\IncludesSharedCompanyRows;
use Webkul\Support\Traits\HasCompanyScope;

/**
 * company_id IS NULL rows ("Buy", "Dropship" — seeded ids 5-6 by
 * RouteSeeder) are system-managed shared references read during
 * procurement rule resolution (InventoryManager::searchRule()), not
 * incomplete records: see ADR 0007 (company_or_shared). Same treatment as
 * Location — visible alongside the user's own companies, mutation guarded
 * to super_admin/system context.
 */
class Route extends Model implements IncludesSharedCompanyRows, Sortable
{
    use HasCompanyScope, HasFactory, SoftDeletes, SortableTrait;

    protected $table = 'inventories_routes';

    protected $fillable = [
        'sort',
        'name',
        'product_selectable',
        'product_category_selectable',
        'warehouse_selectable',
        'packaging_selectable',
        'supplied_warehouse_id',
        'supplier_warehouse_id',
        'company_id',
        'creator_id',
        'deleted_at',
    ];

    protected $casts = [
        'product_selectable'          => 'boolean',
        'product_category_selectable' => 'boolean',
        'warehouse_selectable'        => 'boolean',
        'packaging_selectable'        => 'boolean',
    ];

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    public function suppliedWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplierWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'inventories_route_warehouses');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function packagings(): BelongsToMany
    {
        return $this->belongsToMany(Route::class, 'inventories_route_packagings', 'route_id', 'packaging_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(Rule::class);
    }

    protected static function newFactory(): RouteFactory
    {
        return RouteFactory::new();
    }

    /**
     * Shared (company_id IS NULL) Route records are system-managed
     * references (ADR 0007). Blocks any authenticated non-super_admin from
     * creating a new shared row or mutating/deleting/restoring an existing
     * one; no authenticated user (console, queue, seeders, installer) is a
     * system context and stays unrestricted, matching CompanyScope's own
     * rule for unauthenticated access.
     */
    protected static function guardSharedRowMutation(bool $isNullCompany): void
    {
        if (! $isNullCompany) {
            return;
        }

        if (! Auth::check()) {
            return;
        }

        if (static::actingUserIsSuperAdmin()) {
            return;
        }

        throw new AuthorizationException('Shared Route records (company_id is null) can only be created or modified by a super_admin or a system process.');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($route) {
            $authUser = Auth::user();

            $route->creator_id ??= $authUser->id;

            $route->company_id ??= $authUser?->default_company_id;

            static::guardSharedRowMutation($route->company_id === null);
        });

        static::updating(function (Route $route) {
            static::guardSharedRowMutation($route->getOriginal('company_id') === null);

            if ($route->isDirty('company_id')) {
                throw new AuthorizationException('Changing the company of this record is forbidden at this point, you should rather archive it and create a new one.');
            }
        });

        static::deleting(function (Route $route) {
            static::guardSharedRowMutation($route->company_id === null);
        });

        static::forceDeleting(function (Route $route) {
            static::guardSharedRowMutation($route->company_id === null);
        });

        static::restoring(function (Route $route) {
            static::guardSharedRowMutation($route->company_id === null);
        });
    }
}
