<?php

namespace Webkul\TimeOff\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Security\Models\User;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;
use Webkul\TimeOff\Database\Factories\LeaveAccrualLevelFactory;

/**
 * No company_id of its own — accrual_plan_id is a mandatory FK (migration:
 * cascade-delete), so read isolation is entirely derived by requiring a
 * visible LeaveAccrualPlan, the same shape as Milestone::companyViaProject
 * / ActivityPlanTemplate::companyViaActivityPlan (#138 PR4 ola4B).
 */
class LeaveAccrualLevel extends Model implements Sortable
{
    use HasFactory, SortableTrait, ValidatesRelatedCompanyScope;

    protected $table = 'time_off_leave_accrual_levels';

    protected $fillable = [
        'sort',
        'accrual_plan_id',
        'start_count',
        'first_day',
        'second_day',
        'first_month_day',
        'second_month_day',
        'yearly_day',
        'postpone_max_days',
        'accrual_validity_count',
        'creator_id',
        'start_type',
        'added_value_type',
        'frequency',
        'week_day',
        'first_month',
        'second_month',
        'yearly_month',
        'action_with_unused_accruals',
        'accrual_validity_type',
        'added_value',
        'maximum_leave',
        'maximum_leave_yearly',
        'cap_accrued_time',
        'cap_accrued_time_yearly',
        'accrual_validity',
    ];

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    public function accrualPlan()
    {
        return $this->belongsTo(LeaveAccrualPlan::class, 'accrual_plan_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    protected static function booted(): void
    {
        static::addGlobalScope('companyViaAccrualPlan', function (Builder $builder): void {
            $builder->whereHas('accrualPlan');
        });
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($leaveAccrualLevel) {
            $leaveAccrualLevel->creator_id ??= Auth::id();
        });

        // No company_id to persist — exists purely for its authorization
        // side effect: the persisted LeaveAccrualPlan must exist, have a
        // company of its own, and the acting user must be write-authorized
        // for it.
        static::saving(function (self $leaveAccrualLevel): void {
            static::resolveEffectiveCompanyIdOrFail($leaveAccrualLevel->accrual_plan_id, LeaveAccrualPlan::class, null, 'LeaveAccrualPlan');
        });
    }

    protected static function newFactory(): LeaveAccrualLevelFactory
    {
        return LeaveAccrualLevelFactory::new();
    }
}
