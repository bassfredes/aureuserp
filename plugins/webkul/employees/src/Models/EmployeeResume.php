<?php

namespace Webkul\Employee\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Database\Factories\EmployeeResumeFactory;
use Webkul\Security\Models\User;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

class EmployeeResume extends Model
{
    use HasFactory, ValidatesRelatedCompanyScope;

    protected $table = 'employees_employee_resumes';

    protected $fillable = [
        'employee_id',
        'employee_resume_line_type_id',
        'creator_id',
        'user_id',
        'display_type',
        'start_date',
        'end_date',
        'name',
        'description',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function resumeType()
    {
        return $this->belongsTo(EmployeeResumeLineType::class, 'employee_resume_line_type_id');
    }

    protected static function booted(): void
    {
        // EmployeeResume deliberately carries no company_id column of its
        // own (#138 PR4): its parent Employee is mandatory (non-nullable
        // FK, cascadeOnDelete), so read isolation is entirely derived by
        // requiring a visible Employee. Employee::query()'s own
        // HasCompanyScope global scope applies automatically inside this
        // whereHas subquery, so a EmployeeResume whose Employee is hidden
        // (wrong company, or no user/context at all) is hidden too.
        static::addGlobalScope('companyViaEmployee', function (Builder $builder): void {
            $builder->whereHas('employee');
        });
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($employeeResume) {
            $employeeResume->creator_id ??= Auth::id();
        });

        static::saving(function (self $employeeResume): void {
            // No company_id to persist — this call exists purely for its
            // authorization side effects: the persisted Employee (never a
            // dirty/in-memory relation) must exist, must have a company of
            // its own, and the acting user must be write-authorized for
            // that company. A spoofed or cross-company employee_id is
            // rejected here (same pattern as Milestone/project_id).
            $newCompanyId = static::resolveEffectiveCompanyIdOrFail($employeeResume->employee_id, Employee::class, null, 'Employee');

            if (! $employeeResume->exists) {
                return;
            }

            $persisted = static::withoutGlobalScope('companyViaEmployee')->find($employeeResume->getKey());

            if ($persisted === null) {
                return;
            }

            $originalCompanyId = static::resolveEffectiveCompanyIdOrFail($persisted->employee_id, Employee::class, null, 'Employee');

            if ($originalCompanyId !== $newCompanyId) {
                throw new AuthorizationException('Changing the company of this EmployeeResume (via employee_id) is forbidden — archive it and create a new one instead.');
            }
        });
    }

    protected static function newFactory(): EmployeeResumeFactory
    {
        return EmployeeResumeFactory::new();
    }
}
