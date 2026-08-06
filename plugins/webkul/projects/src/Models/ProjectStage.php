<?php

namespace Webkul\Project\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Project\Database\Factories\ProjectStageFactory;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Contracts\IncludesSharedCompanyRows;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\HasCompanyScope;

/**
 * company_id IS NULL rows (the 4 default stages seeded by
 * ProjectStageSeeder: To Do/In Progress/Done/Cancelled) are system-managed
 * shared references usable by any company's Projects, not incomplete
 * records — same treatment as Route/Location (ADR 0007, company_or_shared).
 * Neither the Filament form nor the API request expose a company_id field
 * today, so every stage created through either surface defaults to the
 * acting user's own company (#138 PR4 ola4B), matching HasStrictCompanyId's
 * own create-time convenience default; mutation of a shared (null-company)
 * row stays guarded to super_admin/system context.
 */
class ProjectStage extends Model implements IncludesSharedCompanyRows, Sortable
{
    use HasCompanyScope, HasFactory, SoftDeletes, SortableTrait;

    protected $table = 'projects_project_stages';

    protected $fillable = [
        'name',
        'is_active',
        'is_collapsed',
        'sort',
        'company_id',
        'creator_id',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'is_collapsed' => 'boolean',
    ];

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'stage_id');
    }

    /**
     * Blocks any authenticated non-super_admin from creating a new shared
     * row or mutating/deleting/restoring an existing one; no authenticated
     * user (console, queue, seeders, installer) is a system context and
     * stays unrestricted, matching CompanyScope's own rule for
     * unauthenticated access (mirrors Route::guardSharedRowMutation()).
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

        throw new AuthorizationException('Shared ProjectStage records (company_id is null) can only be created or modified by a super_admin or a system process.');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $projectStage) {
            $authUser = Auth::user();

            $projectStage->creator_id ??= $authUser?->id;
            $projectStage->company_id ??= $authUser?->default_company_id;

            static::guardSharedRowMutation($projectStage->company_id === null);

            // An explicit company_id (not merely the acting user's own
            // default) must still pass authorization — a user of A knowing
            // B's id is not enough to create a row directly in B (#138 PR4
            // ola4B, same guarantee HasStrictCompanyId provides for
            // standalone strict_company owners).
            if ($projectStage->company_id !== null) {
                CompanyScope::assertCanWriteCompany((int) $projectStage->company_id);
            }
        });

        static::updating(function (self $projectStage) {
            $originalCompanyId = $projectStage->getOriginal('company_id');

            static::guardSharedRowMutation($originalCompanyId === null);

            if ($projectStage->isDirty('company_id')) {
                throw new AuthorizationException('Changing the company of this ProjectStage is forbidden — archive it and create a new one instead.');
            }

            // A non-shared row's own company must be re-authorized on every
            // update, not only when company_id itself changes — an actor
            // who obtains a cross-company ProjectStage via an unscoped
            // query and edits an unrelated field (name, sort, ...) must
            // still be rejected (#138 PR4 ola4B, same guarantee as
            // HasStrictCompanyId). Shared rows are handled entirely by
            // guardSharedRowMutation() above, not here.
            if ($originalCompanyId !== null) {
                CompanyScope::assertCanWriteCompany((int) $originalCompanyId);
            }
        });

        static::deleting(function (self $projectStage) {
            static::guardSharedRowMutation($projectStage->company_id === null);
        });

        static::forceDeleting(function (self $projectStage) {
            static::guardSharedRowMutation($projectStage->company_id === null);
        });

        static::restoring(function (self $projectStage) {
            static::guardSharedRowMutation($projectStage->company_id === null);
        });
    }

    protected static function newFactory(): ProjectStageFactory
    {
        return ProjectStageFactory::new();
    }
}
