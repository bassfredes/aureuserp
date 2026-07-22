<?php

namespace Webkul\Project\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Project\Database\Factories\MilestoneFactory;
use Webkul\Security\Models\User;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

class Milestone extends Model
{
    use HasFactory, ValidatesRelatedCompanyScope;

    protected $table = 'projects_milestones';

    protected $fillable = [
        'name',
        'deadline',
        'is_completed',
        'completed_at',
        'project_id',
        'creator_id',
    ];

    protected $casts = [
        'is_completed' => 'boolean',
        'deadline'     => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        // Milestone deliberately carries no company_id column of its own
        // (#138 PR4 ola4A) — its parent Project is mandatory (non-nullable
        // FK, cascadeOnDelete), so read isolation is entirely derived by
        // requiring a visible Project. Project::query()'s own HasCompanyScope
        // global scope applies automatically inside this whereHas subquery,
        // so a Milestone whose Project is hidden (wrong company, or no
        // user/context at all — CompanyScope fails closed) is hidden too.
        static::addGlobalScope('companyViaProject', function (Builder $builder): void {
            $builder->whereHas('project');
        });
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($milestone) {
            $milestone->creator_id ??= Auth::id();
        });

        static::saving(function (self $milestone): void {
            // No company_id to persist — this call exists purely for its
            // authorization side effects: the persisted Project (never a
            // dirty/in-memory relation) must exist, must have a company of
            // its own, and the acting user must be write-authorized for
            // that company. A spoofed or cross-company project_id is
            // rejected here, closing the same IDOR class as Task/TaskStage
            // (#138 PR4 ola4A).
            $newCompanyId = static::resolveEffectiveCompanyIdOrFail($milestone->project_id, Project::class, null, 'Project');

            if (! $milestone->exists) {
                return;
            }

            // Re-derive the ORIGINAL company from the row as it is actually
            // persisted right now (never the in-memory $milestone, whose
            // project_id may have already been reassigned) — this also
            // re-authorizes the actor against the milestone's CURRENT
            // (possibly hidden, cross-company) owner, closing an attack
            // where an actor obtains a foreign Milestone via an unscoped
            // query and retargets it to a Project in their own company: the
            // update must still be rejected because they were never
            // authorized for the ORIGINAL company either (#138 PR4 ola4A
            // round 2 review). A Project reassignment within the SAME
            // company is allowed; changing company is not.
            $persisted = static::withoutGlobalScope('companyViaProject')->find($milestone->getKey());

            if ($persisted === null) {
                return;
            }

            $originalCompanyId = static::resolveEffectiveCompanyIdOrFail($persisted->project_id, Project::class, null, 'Project');

            if ($originalCompanyId !== $newCompanyId) {
                throw new AuthorizationException('Changing the company of this Milestone (via project_id) is forbidden — archive it and create a new one instead.');
            }
        });
    }

    protected static function newFactory(): MilestoneFactory
    {
        return MilestoneFactory::new();
    }
}
