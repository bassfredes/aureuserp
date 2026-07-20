<?php

namespace Webkul\Project\Models;

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
            static::resolveEffectiveCompanyIdOrFail($milestone->project_id, Project::class, null, 'Project');
        });
    }

    protected static function newFactory(): MilestoneFactory
    {
        return MilestoneFactory::new();
    }
}
