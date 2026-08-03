<?php

namespace Webkul\Sale\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Sale\Database\Factories\OrderOptionFactory;
use Webkul\Security\Models\User;
use Webkul\Support\Models\UOM;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

class OrderOption extends Model implements Sortable
{
    use HasFactory, SortableTrait, ValidatesRelatedCompanyScope;

    protected $table = 'sales_order_options';

    protected $fillable = [
        'sort',
        'order_id',
        'product_id',
        'line_id',
        'uom_id',
        'creator_id',
        'name',
        'quantity',
        'price_unit',
        'discount',
    ];

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function line()
    {
        return $this->belongsTo(OrderLine::class, 'line_id');
    }

    public function uom()
    {
        return $this->belongsTo(UOM::class, 'uom_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    protected static function booted(): void
    {
        // OrderOption deliberately carries no company_id column of its own
        // (#138 PR4): its parent Order's own HasCompanyScope global scope
        // applies automatically inside this whereHas subquery, so an
        // OrderOption whose Order is hidden (wrong company, or no
        // user/context at all) is hidden too. order_id is nullable at the
        // schema level, but no code path in this repo creates an OrderOption
        // without one — a null-order_id row is unreachable by design, not a
        // supported state this scope needs to special-case.
        static::addGlobalScope('companyViaOrder', function (Builder $builder): void {
            $builder->whereHas('order');
        });
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($orderOption) {
            $orderOption->creator_id ??= Auth::id();
        });

        static::saving(function (self $orderOption): void {
            // No company_id to persist — this call exists purely for its
            // authorization side effects: the persisted Order (never a
            // dirty/in-memory relation) must exist, must have a company of
            // its own, and the acting user must be write-authorized for
            // that company. A spoofed or cross-company order_id is
            // rejected here (same pattern as Milestone/project_id).
            $newCompanyId = static::resolveEffectiveCompanyIdOrFail($orderOption->order_id, Order::class, null, 'Order');

            if (! $orderOption->exists) {
                return;
            }

            $persisted = static::withoutGlobalScope('companyViaOrder')->find($orderOption->getKey());

            if ($persisted === null) {
                return;
            }

            $originalCompanyId = static::resolveEffectiveCompanyIdOrFail($persisted->order_id, Order::class, null, 'Order');

            if ($originalCompanyId !== $newCompanyId) {
                throw new AuthorizationException('Changing the company of this OrderOption (via order_id) is forbidden — archive it and create a new one instead.');
            }
        });
    }

    protected static function newFactory(): OrderOptionFactory
    {
        return OrderOptionFactory::new();
    }
}
