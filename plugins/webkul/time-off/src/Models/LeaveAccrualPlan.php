<?php

namespace Webkul\TimeOff\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\HasStrictCompanyId;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;
use Webkul\TimeOff\Database\Factories\LeaveAccrualPlanFactory;
use Webkul\TimeOff\Enums\AccruedGainTime;
use Webkul\TimeOff\Enums\CarryoverDate;
use Webkul\TimeOff\Enums\CarryoverDay;
use Webkul\TimeOff\Enums\CarryoverMonth;

class LeaveAccrualPlan extends Model
{
    use HasCompanyScope, HasFactory, HasStrictCompanyId, ValidatesRelatedCompanyScope;

    protected $table = 'time_off_leave_accrual_plans';

    protected $fillable = [
        'time_off_type_id',
        'company_id',
        'carryover_day',
        'creator_id',
        'name',
        'transition_mode',
        'accrued_gain_time',
        'carryover_date',
        'carryover_month',
        'added_value_type',
        'is_active',
        'is_based_on_worked_time',
    ];

    protected $casts = [
        'accrued_gain_time' => AccruedGainTime::class,
        'carryover_day'     => CarryoverDay::class,
        'carryover_month'   => CarryoverMonth::class,
        'carryover_date'    => CarryoverDate::class,
    ];

    public function timeOffType()
    {
        return $this->belongsTo(LeaveType::class, 'time_off_type_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function leaveAccrualLevels()
    {
        return $this->hasMany(LeaveAccrualLevel::class, 'accrual_plan_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($leaveAccrualPlan) {
            $leaveAccrualPlan->creator_id ??= Auth::id();
        });

        // Runs after HasStrictCompanyId's own `saving` listener has already
        // resolved/authorized $leaveAccrualPlan->company_id — time_off_type_id
        // is independently selectable with no server-side company check
        // today (#138 PR4 ola4B).
        static::saving(function (self $leaveAccrualPlan): void {
            static::assertRelatedBelongsToCompany($leaveAccrualPlan->time_off_type_id, LeaveType::class, 'LeaveType', $leaveAccrualPlan->company_id);
        });
    }

    protected static function newFactory(): LeaveAccrualPlanFactory
    {
        return LeaveAccrualPlanFactory::new();
    }
}
