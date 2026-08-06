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
use Webkul\Project\Database\Factories\TaskStageFactory;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

class TaskStage extends Model implements Sortable
{
    use HasCompanyScope, HasFactory, SoftDeletes, SortableTrait, ValidatesRelatedCompanyScope;

    protected $table = 'projects_task_stages';

    protected $fillable = [
        'name',
        'is_active',
        'is_collapsed',
        'sort',
        'project_id',
        'company_id',
        'user_id',
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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'stage_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($taskStage) {
            $taskStage->creator_id ??= Auth::id();
        });

        static::saving(function (self $taskStage): void {
            // project_id is a mandatory, non-nullable FK on this table (no
            // orphaned-stage case, unlike Task) — the Project is always the
            // authoritative source for the effective company (#138 PR4
            // ola4A), same derivation + reassignment contract as Task.
            $effectiveCompanyId = static::resolveEffectiveCompanyIdOrFail($taskStage->project_id, Project::class, $taskStage->company_id, 'Project');

            $originalCompanyId = $taskStage->exists ? $taskStage->getOriginal('company_id') : null;

            if ($originalCompanyId !== null && (int) $originalCompanyId !== (int) $effectiveCompanyId) {
                throw new AuthorizationException('Changing the company of this TaskStage (via project_id or company_id) is forbidden — archive it and create a new one instead.');
            }

            $taskStage->company_id = $effectiveCompanyId;
        });
    }

    protected static function newFactory(): TaskStageFactory
    {
        return TaskStageFactory::new();
    }
}
