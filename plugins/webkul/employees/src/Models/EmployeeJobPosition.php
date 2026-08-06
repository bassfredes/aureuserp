<?php

namespace Webkul\Employee\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Employee\Database\Factories\EmployeeJobPositionFactory;
use Webkul\Employee\Models\Concerns\GuardsCompanyLifecycleOnSoftDelete;
use Webkul\Field\Traits\HasCustomFields;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\HasStrictCompanyId;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

/**
 * HasCompanyScope + HasStrictCompanyId (#138 PR4 A4D). department_id is
 * validated against the already-authorized company_id; recruiter_id (User,
 * no single company_id) is validated via membership, same rule Employee
 * applies to its own User relations. This saving listener also covers
 * Recruitment\JobPosition (an alias with no boot() override of its own for
 * this event — late static binding, verified empirically, see the
 * RecruitmentEmployeeAliasesCompanyScopeTest suite).
 */
class EmployeeJobPosition extends Model implements Sortable
{
    use GuardsCompanyLifecycleOnSoftDelete, HasCompanyScope, HasCustomFields, HasFactory, HasStrictCompanyId, SoftDeletes, SortableTrait, ValidatesRelatedCompanyScope;

    protected $table = 'employees_job_positions';

    protected $fillable = [
        'sort',
        'expected_employees',
        'no_of_employee',
        'no_of_recruitment',
        'department_id',
        'company_id',
        'creator_id',
        'employment_type_id',
        'recruiter_id',
        'name',
        'description',
        'requirements',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function employees()
    {
        return $this->hasMany(Employee::class, 'job_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function employmentType()
    {
        return $this->belongsTo(EmploymentType::class, 'employment_type_id');
    }

    /**
     * User has no single authoritative company_id — membership is checked
     * via CompanyScope::allowedCompanyIds(), same rule Employee applies to
     * its own User relations (user_id/attendance_manager_id/leave_manager_id).
     */
    private static function assertUserBelongsToCompany(?int $userId, ?int $companyId, string $label): void
    {
        if ($userId === null) {
            return;
        }

        $user = User::find($userId);

        if (! $user || $companyId === null || ! CompanyScope::allowedCompanyIds($user)->contains((int) $companyId)) {
            throw new AuthorizationException("The related {$label} is not a member of this Job Position's company.");
        }
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function (self $employeeJobPosition) {
            // Runs after HasStrictCompanyId's own `saving` listener, so
            // $employeeJobPosition->company_id is already resolved and
            // authorized. Late static binding: static:: here resolves to
            // Recruitment\JobPosition when called on that subclass, so
            // this also covers its recruiter_id/manager_id are validated
            // separately in that subclass's own boot() for the relation it
            // adds (manager_id, an Employee, not a User).
            static::assertRelatedBelongsToCompany($employeeJobPosition->department_id, Department::class, 'Department', $employeeJobPosition->company_id);
            static::assertUserBelongsToCompany($employeeJobPosition->recruiter_id, $employeeJobPosition->company_id, 'Recruiter');
        });

        static::creating(function ($employeeJobPosition) {
            $employeeJobPosition->creator_id ??= Auth::id();
        });
    }

    protected static function newFactory(): EmployeeJobPositionFactory
    {
        return EmployeeJobPositionFactory::new();
    }
}
