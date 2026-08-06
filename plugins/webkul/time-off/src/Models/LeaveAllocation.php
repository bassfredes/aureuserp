<?php

namespace Webkul\TimeOff\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Webkul\Chatter\Traits\HasChatter;
use Webkul\Chatter\Traits\HasLogActivity;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Enums\CompanyContextMode;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;
use Webkul\TimeOff\Database\Factories\LeaveAllocationFactory;
use Webkul\TimeOff\Enums\AllocationType;

/**
 * Child tenant-owned anchored on Employee — there is no `company_id`
 * column on this table by design (approved contract, #138 PR4 ola4B): the
 * existing `employee_company_id` IS the tenant column, always derived from
 * the persisted Employee, never from the acting user directly. Because it
 * isn't literally named `company_id`, HasCompanyScope's own CompanyScope
 * class (which hardcodes that column name) cannot be reused for reads —
 * booted() below replicates the exact same precedence (ADR 0007) filtered
 * on employee_company_id instead, using CompanyScope's own public helpers
 * rather than duplicating their logic.
 */
class LeaveAllocation extends Model
{
    use HasChatter, HasFactory, HasLogActivity, ValidatesRelatedCompanyScope;

    public const ACTIVITY_PLAN_PLUGIN = 'time-off';

    protected $table = 'time_off_leave_allocations';

    public function getModelTitle(): string
    {
        return __('time-off::models/leave-allocation.title');
    }

    protected $fillable = [
        'holiday_status_id',
        'employee_id',
        'employee_company_id',
        'manager_id',
        'approver_id',
        'second_approver_id',
        'department_id',
        'accrual_plan_id',
        'creator_id',
        'name',
        'state',
        'allocation_type',
        'date_from',
        'date_to',
        'last_executed_carryover_date',
        'last_called',
        'actual_last_called',
        'next_call',
        'carried_over_days_expiration_date',
        'notes',
        'already_accrued',
        'number_of_days',
        'number_of_hours_display',
        'yearly_accrued_amount',
        'expiring_carryover_days',
    ];

    public function getLogAttributeLabels(): array
    {
        return [
            'holidayStatus.name'                => __('time-off::models/leave-allocation.log-attributes.time_off_type'),
            'employee.name'                     => __('time-off::models/leave-allocation.log-attributes.employee'),
            'employeeCompany.name'              => __('time-off::models/leave-allocation.log-attributes.employee_company'),
            'approver.name'                     => __('time-off::models/leave-allocation.log-attributes.approver'),
            'secondApprover.name'               => __('time-off::models/leave-allocation.log-attributes.second_approver'),
            'department.name'                   => __('time-off::models/leave-allocation.log-attributes.department'),
            'accrualPlan.name'                  => __('time-off::models/leave-allocation.log-attributes.accrual_plan'),
            'creator.name'                      => __('time-off::models/leave-allocation.log-attributes.created_by'),
            'name'                              => __('time-off::models/leave-allocation.log-attributes.name'),
            'state'                             => __('time-off::models/leave-allocation.log-attributes.state'),
            'allocation_type'                   => __('time-off::models/leave-allocation.log-attributes.allocation_type'),
            'date_from'                         => __('time-off::models/leave-allocation.log-attributes.date_from'),
            'date_to'                           => __('time-off::models/leave-allocation.log-attributes.date_to'),
            'last_executed_carryover_date'      => __('time-off::models/leave-allocation.log-attributes.last_executed_carryover_date'),
            'last_called'                       => __('time-off::models/leave-allocation.log-attributes.last_called'),
            'actual_last_called'                => __('time-off::models/leave-allocation.log-attributes.actual_last_called'),
            'next_call'                         => __('time-off::models/leave-allocation.log-attributes.next_call'),
            'carried_over_days_expiration_date' => __('time-off::models/leave-allocation.log-attributes.carried_over_days_expiration_date'),
            'notes'                             => __('time-off::models/leave-allocation.log-attributes.notes'),
            'already_accrued'                   => __('time-off::models/leave-allocation.log-attributes.already_accrued'),
            'number_of_days'                    => __('time-off::models/leave-allocation.log-attributes.number_of_days'),
            'number_of_hours_display'           => __('time-off::models/leave-allocation.log-attributes.number_of_hours_display'),
            'yearly_accrued_amount'             => __('time-off::models/leave-allocation.log-attributes.yearly_accrued_amount'),
            'expiring_carryover_days'           => __('time-off::models/leave-allocation.log-attributes.expiring_carryover_days'),
        ];
    }

    protected $casts = [
        'allocation_type' => AllocationType::class,
        'date_to'         => 'date',
        'date_from'       => 'date',
        'number_of_days'  => 'decimal:4',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'employee_company_id');
    }

    public function employeeCompany()
    {
        return $this->belongsTo(Company::class, 'employee_company_id');
    }

    public function manager()
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function approver()
    {
        return $this->belongsTo(Employee::class, 'approver_id');
    }

    public function secondApprover()
    {
        return $this->belongsTo(Employee::class, 'second_approver_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function accrualPlan()
    {
        return $this->belongsTo(LeaveAccrualPlan::class, 'accrual_plan_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function holidayStatus()
    {
        return $this->belongsTo(LeaveType::class, 'holiday_status_id');
    }

    /**
     * Same precedence CompanyScope::apply() implements, filtered on
     * employee_company_id instead of company_id — no company_or_shared
     * branch (this table has no shared/NULL-company concept per the
     * approved contract).
     */
    protected static function booted(): void
    {
        static::addGlobalScope('viaEmployeeCompany', function (Builder $builder): void {
            $user = Auth::user();

            if ($user && CompanyContext::current()) {
                throw new LogicException('An authenticated user is active while a CompanyContext is still open — these are mutually exclusive (ADR 0007).');
            }

            if ($user) {
                $companyIds = CompanyScope::allowedCompanyIds($user);

                if ($companyIds->isEmpty()) {
                    $builder->whereRaw('1 = 0');

                    return;
                }

                $builder->whereIn('employee_company_id', $companyIds);

                return;
            }

            $context = CompanyContext::current();

            if ($context?->mode === CompanyContextMode::COMPANY) {
                $builder->where('employee_company_id', $context->companyId);

                return;
            }

            if ($context?->mode === CompanyContextMode::ALL_COMPANIES || $context?->mode === CompanyContextMode::BOOTSTRAP) {
                return;
            }

            $builder->whereRaw('1 = 0');
        });
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($leaveAllocation) {
            $leaveAllocation->creator_id ??= Auth::id();
        });

        static::saving(function (self $leaveAllocation): void {
            $effectiveCompanyId = static::resolveEffectiveCompanyIdOrFail($leaveAllocation->employee_id, Employee::class, $leaveAllocation->employee_company_id, 'Employee');

            $leaveAllocation->employee_company_id = $effectiveCompanyId;

            static::assertRelatedBelongsToCompany($leaveAllocation->manager_id, Employee::class, 'manager', $effectiveCompanyId);
            static::assertRelatedBelongsToCompany($leaveAllocation->approver_id, Employee::class, 'approver', $effectiveCompanyId);
            static::assertRelatedBelongsToCompany($leaveAllocation->second_approver_id, Employee::class, 'second approver', $effectiveCompanyId);
            static::assertRelatedBelongsToCompany($leaveAllocation->department_id, Department::class, 'Department', $effectiveCompanyId);

            if (! $leaveAllocation->exists) {
                return;
            }

            $persisted = static::withoutGlobalScope('viaEmployeeCompany')->find($leaveAllocation->getKey());

            if ($persisted === null) {
                return;
            }

            $originalCompanyId = static::resolveEffectiveCompanyIdOrFail($persisted->employee_id, Employee::class, null, 'Employee');

            if ($originalCompanyId !== $effectiveCompanyId) {
                throw new AuthorizationException('Changing the company of this LeaveAllocation (via employee_id) is forbidden — archive it and create a new one instead.');
            }
        });
    }

    protected static function newFactory(): LeaveAllocationFactory
    {
        return LeaveAllocationFactory::new();
    }
}
