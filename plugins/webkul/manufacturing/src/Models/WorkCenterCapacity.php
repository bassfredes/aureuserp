<?php

namespace Webkul\Manufacturing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Security\Models\User;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

/**
 * No company_id column of its own (#138 review round 2, 2026-07-18) — the
 * WorkCenter is the authoritative anchor, and product_id must belong to
 * that same company.
 */
class WorkCenterCapacity extends Model
{
    use ValidatesRelatedCompanyScope;

    protected $table = 'manufacturing_work_center_capacities';

    protected $fillable = [
        'work_center_id',
        'product_id',
        'capacity',
        'time_start',
        'time_stop',
        'creator_id',
    ];

    protected $casts = [
        'capacity'   => 'decimal:4',
        'time_start' => 'decimal:4',
        'time_stop'  => 'decimal:4',
    ];

    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class, 'work_center_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $capacity): void {
            $capacity->creator_id ??= Auth::id();
            $capacity->capacity ??= 1;
            $capacity->time_start ??= 0;
            $capacity->time_stop ??= 0;
        });

        static::saving(function (self $capacity): void {
            // Full check on create or whenever either FK changes;
            // otherwise still re-authorize the WorkCenter's company on
            // every save, even when neither FK is dirty (#138 review
            // round 3, 2026-07-18).
            if (! $capacity->exists || $capacity->isDirty(['work_center_id', 'product_id'])) {
                static::assertProductMatchesWorkCenter($capacity->work_center_id, $capacity->product_id);

                return;
            }

            static::resolveEffectiveCompanyIdOrFail($capacity->work_center_id, WorkCenter::class, null, 'Work Center');
        });
    }

    private static function assertProductMatchesWorkCenter(?int $workCenterId, ?int $productId): void
    {
        $workCenterCompanyId = static::resolveEffectiveCompanyIdOrFail($workCenterId, WorkCenter::class, null, 'Work Center');

        static::assertRelatedBelongsToCompany($productId, Product::class, 'Product', $workCenterCompanyId);
    }
}
