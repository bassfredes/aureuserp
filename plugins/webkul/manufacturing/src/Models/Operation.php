<?php

namespace Webkul\Manufacturing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Manufacturing\Database\Factories\OperationFactory;
use Webkul\Manufacturing\Enums\OperationTimeMode;
use Webkul\Manufacturing\Enums\OperationWorksheetType;
use Webkul\Product\Models\ProductAttributeValue;
use Webkul\Security\Models\User;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

/**
 * No company_id column of its own (#138 review round 2, 2026-07-18) — its
 * effective company is always the owning BillOfMaterial's, so relation
 * integrity is enforced by comparing work_center_id's company against the
 * BOM's rather than against a column on this model.
 */
class Operation extends Model implements Sortable
{
    use HasFactory, SoftDeletes, SortableTrait, ValidatesRelatedCompanyScope;

    protected $table = 'manufacturing_operations';

    protected $fillable = [
        'sort',
        'time_mode_batch',
        'name',
        'worksheet_type',
        'worksheet',
        'worksheet_google_slide_url',
        'time_mode',
        'note',
        'manual_cycle_time',
        'work_center_id',
        'bill_of_material_id',
        'creator_id',
        'deleted_at',
    ];

    protected $casts = [
        'worksheet_type'    => OperationWorksheetType::class,
        'time_mode'         => OperationTimeMode::class,
        'manual_cycle_time' => 'decimal:4',
    ];

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    public function getModelTitle(): string
    {
        return __('manufacturing::models/operation.title');
    }

    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class, 'work_center_id')->withTrashed();
    }

    public function billOfMaterial(): BelongsTo
    {
        return $this->belongsTo(BillOfMaterial::class, 'bill_of_material_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillOfMaterialLine::class, 'operation_id');
    }

    public function byproducts(): HasMany
    {
        return $this->hasMany(BillOfMaterialByproduct::class, 'operation_id');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'operation_id');
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(ProductAttributeValue::class, 'manufacturing_operation_attribute_values', 'operation_id', 'product_attribute_value_id');
    }

    public function blockedByOperations(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'manufacturing_operation_dependencies', 'operation_id', 'depends_on_operation_id');
    }

    public function dependentOperations(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'manufacturing_operation_dependencies', 'depends_on_operation_id', 'operation_id');
    }

    public function getTimeCycleAttribute()
    {
        if ($this->time_mode === OperationTimeMode::MANUAL) {
            return $this->manual_cycle_time;
        }

        $workOrders = WorkOrder::query()
            ->where('operation_id', $this->id)
            ->where('quantity_produced', '>', 0)
            ->where('state', 'done')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->limit($this->time_mode_batch)
            ->get();

        $totalDuration = 0;

        $cycleNumber = 0;

        foreach ($workOrders as $workOrder) {
            $totalDuration += $workOrder->duration;

            $capacity = $workOrder->workCenter->getCapacity($workOrder->product);

            $qtyProduced = $workOrder->uom->computeQuantity($workOrder->quantity_produced, $workOrder->product->uom);

            $cycleNumber += float_round(($qtyProduced / $capacity) ?: 1.0, precisionDigits: 0, roundingMethod: 'UP');
        }

        return $cycleNumber ? ($totalDuration / $cycleNumber) : $this->manual_cycle_time;
    }

    public function getExpectedDuration(?Product $product = null, float $quantity = 1): float
    {
        $workCenter = $this->workCenter;

        if (! $workCenter) {
            return $this->time_cycle;
        }

        $normalizedQuantity = max($quantity, 0);

        $capacity = $workCenter->getCapacity($product);

        $cycleNumber = $normalizedQuantity > 0 ? (float) ceil($normalizedQuantity / $capacity) : 0.0;

        $timeEfficiency = max((float) ($workCenter->time_efficiency ?? 100), 0.0001);

        return $workCenter->getExpectedDuration($product)
            + ($cycleNumber * $this->time_cycle * 100.0 / $timeEfficiency);
    }

    public function getExpectedCost(?Product $product = null, float $quantity = 1): float
    {
        return ($this->getExpectedDuration($product, $quantity) / 60.0)
            * (float) ($this->workCenter?->costs_per_hour ?? 0);
    }

    protected static function newFactory(): OperationFactory
    {
        return OperationFactory::new();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $operation): void {
            $operation->creator_id ??= Auth::id();
            $operation->worksheet_type ??= OperationWorksheetType::TEXT;
            $operation->time_mode ??= OperationTimeMode::MANUAL;

            static::assertWorkCenterMatchesBillOfMaterial($operation->bill_of_material_id, $operation->work_center_id);
        });

        static::updating(function (self $operation): void {
            if ($operation->isDirty(['bill_of_material_id', 'work_center_id'])) {
                static::assertWorkCenterMatchesBillOfMaterial($operation->bill_of_material_id, $operation->work_center_id);
            }
        });
    }

    /**
     * Operation has no company_id of its own — the BillOfMaterial is the
     * authoritative anchor, and work_center_id must belong to that same
     * company (#138 review round 2, 2026-07-18). Reusing
     * resolveEffectiveCompanyIdOrFail() to resolve the BOM's company also
     * gets write-authorization for free: an actor may only create/update an
     * Operation under a BOM whose company they're authorized to write to.
     */
    private static function assertWorkCenterMatchesBillOfMaterial(?int $billOfMaterialId, ?int $workCenterId): void
    {
        $bomCompanyId = static::resolveEffectiveCompanyIdOrFail($billOfMaterialId, BillOfMaterial::class, null, 'Bill Of Material');

        static::assertRelatedBelongsToCompany($workCenterId, WorkCenter::class, 'Work Center', $bomCompanyId);
    }
}
