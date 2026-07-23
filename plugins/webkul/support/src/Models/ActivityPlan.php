<?php

namespace Webkul\Support\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Webkul\Field\Traits\HasCustomFields;
use Webkul\Security\Models\User;
use Webkul\Support\Database\Factories\ActivityPlanFactory;
use Webkul\Support\Models\Contracts\IncludesSharedCompanyRows;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\HasCompanyScope;

/**
 * Physical owner of the `activity_plans` table — Employee\ActivityPlan,
 * Recruitment\ActivityPlan, Project\ActivityPlan and Sale\ActivityPlan are
 * thin, logic-free subclasses that inherit this scoping automatically
 * (#138 PR4 ola4B). company_id IS NULL rows (ActivityPlanSeeder's default
 * plans) are system-managed shared references usable by any company, not
 * incomplete records — same treatment as Route/Location/ProjectStage (ADR
 * 0007, company_or_shared). None of the 4 plugin-specific Filament
 * Resources expose a company_id field on their own account-scoped
 * `modifyQueryUsing(...->employees()/...->sales()/etc.)` table query, only
 * the `plugin` discriminator — every plan created through any of them
 * defaults to the acting user's own company; mutation of a shared
 * (null-company) row stays guarded to super_admin/system context.
 */
class ActivityPlan extends Model implements IncludesSharedCompanyRows
{
    use HasCompanyScope, HasCustomFields, HasFactory, SoftDeletes;

    protected $table = 'activity_plans';

    protected $fillable = [
        'company_id',
        'plugin',
        'creator_id',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function activityTypes(): HasMany
    {
        return $this->hasMany(ActivityType::class, 'activity_plan_id');
    }

    public function activityPlanTemplates(): HasMany
    {
        return $this->hasMany(ActivityPlanTemplate::class, 'plan_id');
    }

    public function scopeForPlugin(Builder $query, string $plugin): Builder
    {
        return $query->where('plugin', $plugin);
    }

    public function scopeSales(Builder $query): Builder
    {
        return $query->forPlugin('sales');
    }

    public function scopePurchases(Builder $query): Builder
    {
        return $query->forPlugin('purchases');
    }

    public function scopeProjects(Builder $query): Builder
    {
        return $query->forPlugin('projects');
    }

    public function scopeEmployees(Builder $query): Builder
    {
        return $query->forPlugin('employees');
    }

    public function scopeRecruitments(Builder $query): Builder
    {
        return $query->forPlugin('recruitments');
    }

    public function scopePartners(Builder $query): Builder
    {
        return $query->forPlugin('partners');
    }

    public function scopeAccounts(Builder $query): Builder
    {
        return $query->forPlugin('accounts');
    }

    public function scopeInventories(Builder $query): Builder
    {
        return $query->forPlugin('inventories');
    }

    public function scopeInvoices(Builder $query): Builder
    {
        return $query->forPlugin('invoices');
    }

    public function scopeAccounting(Builder $query): Builder
    {
        return $query->forPlugin('accounting');
    }

    public function scopeProducts(Builder $query): Builder
    {
        return $query->forPlugin('products');
    }

    public function scopeTimeOff(Builder $query): Builder
    {
        return $query->forPlugin('time-off');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Mirrors ProjectStage/Route::guardSharedRowMutation() — blocks any
     * authenticated non-super_admin from creating a new shared row or
     * mutating/deleting/restoring an existing one; no authenticated user
     * (console, queue, seeders, installer) is a system context and stays
     * unrestricted.
     */
    protected static function guardSharedRowMutation(bool $isNullCompany): void
    {
        if (! $isNullCompany) {
            return;
        }

        if (! Auth::check()) {
            return;
        }

        if (static::actingUserIsSuperAdmin()) {
            return;
        }

        throw new AuthorizationException('Shared ActivityPlan records (company_id is null) can only be created or modified by a super_admin or a system process.');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $activityPlan) {
            $authUser = Auth::user();

            $activityPlan->creator_id ??= $authUser?->id;
            $activityPlan->company_id ??= $authUser?->default_company_id;

            static::guardSharedRowMutation($activityPlan->company_id === null);

            // An explicit company_id (not merely the acting user's own
            // default) must still pass authorization — a user of A knowing
            // B's id is not enough to create a row directly in B (#138 PR4
            // ola4B). Employee's and Sale's own ActivityPlanResource forms
            // expose a free-choosing company_id Select with no server-side
            // check today; this closes that exact gap.
            if ($activityPlan->company_id !== null) {
                CompanyScope::assertCanWriteCompany((int) $activityPlan->company_id);
            }
        });

        static::updating(function (self $activityPlan) {
            $originalCompanyId = $activityPlan->getOriginal('company_id');

            static::guardSharedRowMutation($originalCompanyId === null);

            if ($activityPlan->isDirty('company_id')) {
                throw new AuthorizationException('Changing the company of this ActivityPlan is forbidden — archive it and create a new one instead.');
            }

            if ($originalCompanyId !== null) {
                CompanyScope::assertCanWriteCompany((int) $originalCompanyId);
            }
        });

        static::deleting(function (self $activityPlan) {
            static::guardSharedRowMutation($activityPlan->company_id === null);
        });

        static::forceDeleting(function (self $activityPlan) {
            static::guardSharedRowMutation($activityPlan->company_id === null);
        });

        static::restoring(function (self $activityPlan) {
            static::guardSharedRowMutation($activityPlan->company_id === null);
        });
    }

    protected static function newFactory(): ActivityPlanFactory
    {
        return ActivityPlanFactory::new();
    }
}
