<?php

namespace Webkul\Employee\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Database\Factories\EmployeeSkillFactory;
use Webkul\Employee\Models\Scopes\EmployeeSkillCompanyScope;
use Webkul\Security\Models\User;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

/**
 * EmployeeSkill has no company_id column of its own (#138 PR4 A4D) —
 * isolation is entirely parent-derived from Employee. Read: a bespoke
 * global scope (EmployeeSkillCompanyScope), not HasCompanyScope, since the
 * tenant column lives on a related model, not this one — mirrors the
 * BankAccountCompanyMembershipScope precedent (ola 4C). Write: reauthorizes
 * against the persisted Employee (resolveEffectiveCompanyIdOrFail(), which
 * already bypasses scope, includes soft-deleted parents, and calls
 * CompanyScope::assertCanWriteCompany() internally) on every
 * create/update/delete/restore/forceDelete, never trusting an in-memory
 * Employee or EmployeeSkill object as authority.
 */
class EmployeeSkill extends Model
{
    use HasFactory, SoftDeletes, ValidatesRelatedCompanyScope;

    protected $table = 'employees_employee_skills';

    protected $fillable = [
        'employee_id',
        'skill_id',
        'skill_level_id',
        'skill_type_id',
        'creator_id',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }

    public function skillLevel()
    {
        return $this->belongsTo(SkillLevel::class);
    }

    public function skillType()
    {
        return $this->belongsTo(SkillType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Authorizes against a given Employee id, returning its effective
     * company_id. Returns null only when there is no employee_id to
     * resolve at all (should not happen in practice — employee_id is
     * required for any meaningful EmployeeSkill — but this helper does
     * not itself enforce that requirement, only company authorization).
     */
    private static function authorizeAgainstPersistedEmployee(?int $employeeId): ?int
    {
        if ($employeeId === null) {
            return null;
        }

        return static::resolveEffectiveCompanyIdOrFail($employeeId, Employee::class, null, 'Employee');
    }

    /**
     * Re-queries employee_id fresh by primary key, bypassing this
     * model's own scope AND soft-delete scope, never getOriginal() and
     * never the in-memory attribute. getOriginal() is not enough: a row
     * fetched via a partial projection (e.g. ::select('id')->find(...))
     * never has employee_id populated at all, so getOriginal('employee_id')
     * silently returns null and every guard below would have skipped
     * authorization entirely for exactly the row it exists to protect
     * (#138 PR4 A4D review 4811425870, finding 2).
     */
    private static function resolvePersistedEmployeeId(self $employeeSkill): ?int
    {
        if (! $employeeSkill->exists) {
            return null;
        }

        $employeeId = static::withTrashed()
            ->withoutGlobalScope(EmployeeSkillCompanyScope::class)
            ->whereKey($employeeSkill->getKey())
            ->value('employee_id');

        return $employeeId !== null ? (int) $employeeId : null;
    }

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope(new EmployeeSkillCompanyScope);

        static::creating(function ($employeeSkill) {
            $employeeSkill->creator_id ??= Auth::id();

            static::authorizeAgainstPersistedEmployee($employeeSkill->employee_id);
        });

        static::updating(function (self $employeeSkill) {
            $originalEmployeeId = static::resolvePersistedEmployeeId($employeeSkill);
            $originalCompanyId = static::authorizeAgainstPersistedEmployee($originalEmployeeId);

            if ($employeeSkill->isDirty('employee_id')) {
                $newCompanyId = static::authorizeAgainstPersistedEmployee($employeeSkill->employee_id);

                if ($originalCompanyId !== null && $newCompanyId !== null && $originalCompanyId !== $newCompanyId) {
                    throw new AuthorizationException('Moving an EmployeeSkill to an Employee in a different company is forbidden.');
                }
            }
        });

        static::deleting(function (self $employeeSkill) {
            static::authorizeAgainstPersistedEmployee(static::resolvePersistedEmployeeId($employeeSkill));
        });

        static::restoring(function (self $employeeSkill) {
            static::authorizeAgainstPersistedEmployee(static::resolvePersistedEmployeeId($employeeSkill));
        });

        static::forceDeleting(function (self $employeeSkill) {
            static::authorizeAgainstPersistedEmployee(static::resolvePersistedEmployeeId($employeeSkill));
        });
    }

    protected static function newFactory(): EmployeeSkillFactory
    {
        return EmployeeSkillFactory::new();
    }
}
