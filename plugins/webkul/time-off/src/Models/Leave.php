<?php

namespace Webkul\TimeOff\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Chatter\Traits\HasChatter;
use Webkul\Chatter\Traits\HasLogActivity;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;
use Webkul\TimeOff\Database\Factories\LeaveFactory;
use Webkul\TimeOff\Enums\RequestDateFromPeriod;
use Webkul\TimeOff\Enums\State;

/**
 * company_id and employee_company_id both derive from the persisted
 * Employee — never from the acting user's own default_company_id — and
 * manager/first_approver/second_approver/department must all belong to
 * that same company (#138 PR4 ola4B, approved contract). employee_id is a
 * mandatory FK (migration: restrictOnDelete), so resolveEffectiveCompanyIdOrFail
 * is the strict variant, matching TaskStage/Milestone's own FK-anchored
 * pattern from ola4A.
 */
class Leave extends Model
{
    use HasChatter, HasCompanyScope, HasFactory, HasLogActivity, ValidatesRelatedCompanyScope;

    public const ACTIVITY_PLAN_PLUGIN = 'time-off';

    protected $table = 'time_off_leaves';

    public function getModelTitle(): string
    {
        return __('time-off::models/leave.title');
    }

    protected $fillable = [
        'user_id',
        'manager_id',
        'holiday_status_id',
        'employee_id',
        'employee_company_id',
        'company_id',
        'department_id',
        'calendar_id',
        'meeting_id',
        'first_approver_id',
        'second_approver_id',
        'creator_id',
        'private_name',
        'attachment',
        'state',
        'duration_display',
        'request_date_from_period',
        'request_date_from',
        'request_date_to',
        'notes',
        'request_unit_half',
        'request_unit_hours',
        'date_from',
        'date_to',
        'number_of_days',
        'number_of_hours',
        'request_hour_from',
        'request_hour_to',
    ];

    public function getLogAttributeLabels(): array
    {
        return [
            'user.name'                => __('time-off::models/leave.log-attributes.user'),
            'manger.name'              => __('time-off::models/leave.log-attributes.manager'),
            'holidayStatus.name'       => __('time-off::models/leave.log-attributes.holiday_status'),
            'employee.name'            => __('time-off::models/leave.log-attributes.employee'),
            'employeeCompany.name'     => __('time-off::models/leave.log-attributes.employee_company'),
            'department.name'          => __('time-off::models/leave.log-attributes.department'),
            'calendar.name'            => __('time-off::models/leave.log-attributes.calendar'),
            'firstApprover.name'       => __('time-off::models/leave.log-attributes.first_approver'),
            'lastApprover.name'        => __('time-off::models/leave.log-attributes.last_approver'),
            'private_name'             => __('time-off::models/leave.log-attributes.description'),
            'state'                    => __('time-off::models/leave.log-attributes.state'),
            'duration_display'         => __('time-off::models/leave.log-attributes.duration_display'),
            'request_date_from_period' => __('time-off::models/leave.log-attributes.request_date_from_period'),
            'request_date_from'        => __('time-off::models/leave.log-attributes.request_date_from'),
            'request_date_to'          => __('time-off::models/leave.log-attributes.request_date_to'),
            'notes'                    => __('time-off::models/leave.log-attributes.notes'),
            'request_unit_half'        => __('time-off::models/leave.log-attributes.request_unit_half'),
            'request_unit_hours'       => __('time-off::models/leave.log-attributes.request_unit_hours'),
            'date_from'                => __('time-off::models/leave.log-attributes.date_from'),
            'date_to'                  => __('time-off::models/leave.log-attributes.date_to'),
            'number_of_days'           => __('time-off::models/leave.log-attributes.number_of_days'),
            'number_of_hours'          => __('time-off::models/leave.log-attributes.number_of_hours'),
            'request_hour_from'        => __('time-off::models/leave.log-attributes.request_hour_from'),
            'request_hour_to'          => __('time-off::models/leave.log-attributes.request_hour_to'),
        ];
    }

    protected $casts = [
        'state'                    => State::class,
        'request_date_from_period' => RequestDateFromPeriod::class,
        'request_date_from'        => 'date',
        'date_from'                => 'date',
        'request_unit_half'        => 'boolean',
        'number_of_hours'          => 'decimal:4',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function holidayStatus(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'holiday_status_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function employeeCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'employee_company_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class, 'calendar_id');
    }

    public function firstApprover(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'first_approver_id');
    }

    public function secondApprover(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'second_approver_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $leave) {
            $leave->creator_id ??= Auth::id();
        });

        static::saving(function (self $leave): void {
            $effectiveCompanyId = static::resolveEffectiveCompanyIdOrFail($leave->employee_id, Employee::class, $leave->company_id, 'Employee');

            $leave->company_id = $effectiveCompanyId;
            $leave->employee_company_id = $effectiveCompanyId;

            static::assertRelatedBelongsToCompany($leave->manager_id, Employee::class, 'manager', $effectiveCompanyId);
            static::assertRelatedBelongsToCompany($leave->first_approver_id, Employee::class, 'first approver', $effectiveCompanyId);
            static::assertRelatedBelongsToCompany($leave->second_approver_id, Employee::class, 'second approver', $effectiveCompanyId);
            static::assertRelatedBelongsToCompany($leave->department_id, Department::class, 'Department', $effectiveCompanyId);

            if (! $leave->exists) {
                return;
            }

            // Re-derive the ORIGINAL company from the row as it is actually
            // persisted right now (never the in-memory $leave, whose
            // employee_id may have already been reassigned) — closes the
            // same retargeting IDOR class Milestone/TaskStage close in ola4A.
            $persisted = static::withoutGlobalScope(CompanyScope::class)->find($leave->getKey());

            if ($persisted === null) {
                return;
            }

            $originalCompanyId = static::resolveEffectiveCompanyIdOrFail($persisted->employee_id, Employee::class, null, 'Employee');

            if ($originalCompanyId !== $effectiveCompanyId) {
                throw new AuthorizationException('Changing the company of this Leave (via employee_id) is forbidden — archive it and create a new one instead.');
            }
        });
    }

    protected static function newFactory(): LeaveFactory
    {
        return LeaveFactory::new();
    }
}
