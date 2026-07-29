<?php

namespace Webkul\Employee\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Webkul\Chatter\Traits\HasChatter;
use Webkul\Chatter\Traits\HasLogActivity;
use Webkul\Employee\Database\Factories\DepartmentFactory;
use Webkul\Employee\Models\Concerns\GuardsCompanyLifecycleOnSoftDelete;
use Webkul\Field\Traits\HasCustomFields;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\HasStrictCompanyId;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

/**
 * HasCompanyScope + HasStrictCompanyId (#138 PR4 A4D). The hierarchy
 * helpers below (validateNoRecursion/handleDepartmentData/
 * findTopLevelParentId/getCompleteName) now resolve the real persisted
 * parent chain via withoutGlobalScope(CompanyScope::class) instead of a
 * scoped static::find() — previously, a parent hidden by the (not yet
 * existing) scope would have been silently treated as "no parent" via the
 * null-safe operator, rather than rejected. manager_id (Employee) is
 * validated via ValidatesRelatedCompanyScope; parent_id/master_department_id
 * are self-relations, validated the same way.
 */
class Department extends Model
{
    use GuardsCompanyLifecycleOnSoftDelete, HasChatter, HasCompanyScope, HasCustomFields, HasFactory, HasLogActivity, HasStrictCompanyId, SoftDeletes, ValidatesRelatedCompanyScope;

    public const ACTIVITY_PLAN_PLUGIN = 'employees';

    protected $table = 'employees_departments';

    protected $fillable = [
        'name',
        'manager_id',
        'company_id',
        'parent_id',
        'master_department_id',
        'complete_name',
        'parent_path',
        'creator_id',
        'color',
    ];

    public function getModelTitle(): string
    {
        return __('employees::models/department.title');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }

    public function masterDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'master_department_id');
    }

    public function jobPositions(): HasMany
    {
        return $this->hasMany(EmployeeJobPosition::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    protected static function newFactory(): DepartmentFactory
    {
        return DepartmentFactory::new();
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function (self $department) {
            // Runs after HasStrictCompanyId's own `saving` listener, so
            // $department->company_id is already resolved/authorized.
            static::assertRelatedBelongsToCompany($department->manager_id, Employee::class, 'Manager', $department->company_id);
        });

        static::creating(function ($department) {
            $department->creator_id ??= Auth::id();

            if (! static::validateNoRecursion($department)) {
                throw new InvalidArgumentException('Circular reference detected in department hierarchy');
            }

            static::handleDepartmentData($department);
        });

        static::updating(function ($department) {
            if (! static::validateNoRecursion($department)) {
                throw new InvalidArgumentException('Circular reference detected in department hierarchy');
            }

            static::handleDepartmentData($department);
        });
    }

    protected static function validateNoRecursion($department)
    {
        if (! $department->parent_id) {
            return true;
        }

        if ($department->exists && $department->id == $department->parent_id) {
            return false;
        }

        $visitedIds = [$department->exists ? $department->id : -1];
        $currentParentId = $department->parent_id;

        while ($currentParentId) {
            if (in_array($currentParentId, $visitedIds)) {
                return false;
            }

            $visitedIds[] = $currentParentId;
            $parent = static::withoutGlobalScope(CompanyScope::class)->find($currentParentId);

            if (! $parent) {
                break;
            }

            $currentParentId = $parent->parent_id;
        }

        return true;
    }

    /**
     * Resolves and validates a single ancestor hop: existence and company
     * match against $expectedCompanyId (the department's own, immutable,
     * already-authorized company_id). Bypasses CompanyScope like the rest
     * of this hierarchy, since a Department must be able to see its own
     * company's full ancestor chain regardless of the querying actor's
     * active CompanyContext. Used consistently by handleDepartmentData(),
     * findTopLevelParentId() and getCompleteName() so every ancestor hop —
     * not only the immediate parent — is validated the same way (#138 PR4
     * A4D review 4811425870, finding 4: a corrupted/historical chain could
     * otherwise incorporate a cross-company ancestor further up the tree,
     * or crash on a null access).
     */
    protected static function resolveValidatedAncestor(int $parentId, int $expectedCompanyId): self
    {
        $parent = static::withoutGlobalScope(CompanyScope::class)->find($parentId);

        if (! $parent) {
            throw new AuthorizationException('An ancestor Department could not be found.');
        }

        if ((int) $parent->company_id !== $expectedCompanyId) {
            throw new AuthorizationException('An ancestor Department belongs to a different company.');
        }

        return $parent;
    }

    protected static function handleDepartmentData($department)
    {
        $companyId = (int) $department->company_id;

        if ($department->parent_id) {
            $parent = static::resolveValidatedAncestor((int) $department->parent_id, $companyId);

            $department->parent_path = $parent->parent_path.$parent->id.'/';

            $department->master_department_id = static::findTopLevelParentId($parent, $companyId);
        } else {
            $department->parent_path = '/';
            $department->master_department_id = null;
        }

        $department->complete_name = static::getCompleteName($department, $companyId);
    }

    protected static function findTopLevelParentId($department, int $expectedCompanyId)
    {
        $currentDepartment = $department;

        while ($currentDepartment->parent_id) {
            $currentDepartment = static::resolveValidatedAncestor((int) $currentDepartment->parent_id, $expectedCompanyId);
        }

        return $currentDepartment->id;
    }

    protected static function getCompleteName($department, int $expectedCompanyId)
    {
        $names = [];

        $names[] = $department->name;

        $currentDepartment = $department;

        while ($currentDepartment->parent_id) {
            $currentDepartment = static::resolveValidatedAncestor((int) $currentDepartment->parent_id, $expectedCompanyId);

            array_unshift($names, $currentDepartment->name);
        }

        return implode(' / ', $names);
    }
}
