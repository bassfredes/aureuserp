<?php

namespace Webkul\Project\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Chatter\Traits\HasChatter;
use Webkul\Chatter\Traits\HasLogActivity;
use Webkul\Field\Traits\HasCustomFields;
use Webkul\Partner\Models\Partner;
use Webkul\Project\Database\Factories\TaskFactory;
use Webkul\Project\Enums\TaskState;
use Webkul\Security\Models\Scopes\UserPermissionScope;
use Webkul\Security\Models\User;
use Webkul\Security\Traits\HasPermissionScope;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

class Task extends Model implements Sortable
{
    use HasChatter, HasCompanyScope, HasCustomFields, HasFactory, HasLogActivity, HasPermissionScope, SoftDeletes, SortableTrait, ValidatesRelatedCompanyScope;

    public const ACTIVITY_PLAN_PLUGIN = 'projects';

    protected $table = 'projects_tasks';

    protected $fillable = [
        'title',
        'description',
        'color',
        'priority',
        'state',
        'sort',
        'is_active',
        'is_recurring',
        'deadline',
        'working_hours_open',
        'working_hours_close',
        'allocated_hours',
        'remaining_hours',
        'effective_hours',
        'total_hours_spent',
        'subtask_effective_hours',
        'overtime',
        'progress',
        'stage_id',
        'project_id',
        'partner_id',
        'parent_id',
        'company_id',
        'creator_id',
    ];

    protected $casts = [
        'deadline'            => 'datetime',
        'priority'            => 'boolean',
        'is_active'           => 'boolean',
        'is_recurring'        => 'boolean',
        'working_hours_open'  => 'float',
        'working_hours_close' => 'float',
        'allocated_hours'     => 'float',
        'remaining_hours'     => 'float',
        'effective_hours'     => 'float',
        'total_hours_spent'   => 'float',
        'overtime'            => 'float',
        'state'               => TaskState::class,
    ];

    public string $recordTitleAttribute = 'title';

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setPivotTable('projects_task_users');

        $this->setPivotForeignKey('task_id');

        $this->setPivotRelatedKey('user_id');
    }

    protected function getLogAttributeLabels(): array
    {
        return [
            'title'             => __('projects::models/task.log-attributes.title'),
            'description'       => __('projects::models/task.log-attributes.description'),
            'color'             => __('projects::models/task.log-attributes.color'),
            'priority'          => __('projects::models/task.log-attributes.priority'),
            'state'             => __('projects::models/task.log-attributes.state'),
            'sort'              => __('projects::models/task.log-attributes.sort'),
            'is_active'         => __('projects::models/task.log-attributes.is_active'),
            'is_recurring'      => __('projects::models/task.log-attributes.is_recurring'),
            'deadline'          => __('projects::models/task.log-attributes.deadline'),
            'allocated_hours'   => __('projects::models/task.log-attributes.allocated_hours'),
            'stage.name'        => __('projects::models/task.log-attributes.stage'),
            'project.name'      => __('projects::models/task.log-attributes.project'),
            'partner.name'      => __('projects::models/task.log-attributes.partner'),
            'parent.title'      => __('projects::models/task.log-attributes.parent'),
            'company.name'      => __('projects::models/task.log-attributes.company'),
            'creator.name'      => __('projects::models/task.log-attributes.creator'),
        ];
    }

    public function getModelTitle(): string
    {
        return __('projects::models/task.title');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class);
    }

    public function subTasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(TaskStage::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'projects_task_users');
    }

    public function chatterResponsibles(): array
    {
        return ['users'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'projects_task_tag', 'task_id', 'tag_id');
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class);
    }

    protected static function booted()
    {
        static::addGlobalScope(new UserPermissionScope('users'));
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($task) {
            $task->creator_id ??= Auth::id();
        });

        static::saving(function (self $task): void {
            // The effective company is derived from the persisted Project,
            // not trusted from the task's own mutable company_id column —
            // a project_id is mandatory (#138 PR4 ola4A). Only a row that
            // was ALREADY orphaned before this exact save (its Project was
            // deleted via nullOnDelete, outside any Eloquent hook) may keep
            // saving without one, re-authorizing its own already-persisted
            // company_id — a brand new Task with no project, or an existing
            // Task being manually detached from its Project, is rejected
            // outright (#138 PR4 ola4A round 2 review).
            $wasAlreadyOrphaned = $task->exists && $task->getOriginal('project_id') === null;

            if ($task->project_id !== null) {
                $effectiveCompanyId = static::resolveEffectiveCompanyIdOrFail($task->project_id, Project::class, $task->company_id, 'Project');
            } elseif ($wasAlreadyOrphaned) {
                $effectiveCompanyId = $task->company_id ?? Auth::user()?->default_company_id;

                if ($effectiveCompanyId === null) {
                    throw new AuthorizationException(static::class.' requires a company_id and none could be resolved from the acting user.');
                }

                CompanyScope::assertCanWriteCompany((int) $effectiveCompanyId);
            } else {
                throw new AuthorizationException('A Task requires a project_id.');
            }

            // A project_id reassignment (alone, or paired with a matching
            // explicit company_id) that would move an already-persisted
            // Task to a different company is rejected outright — the same
            // "archive and recreate instead" rule as Project's own
            // company_id, just derived through the parent (#138 PR4 ola4A).
            $originalCompanyId = $task->exists ? $task->getOriginal('company_id') : null;

            if ($originalCompanyId !== null && (int) $originalCompanyId !== (int) $effectiveCompanyId) {
                throw new AuthorizationException('Changing the company of this Task (via project_id or company_id) is forbidden — archive it and create a new one instead.');
            }

            $task->company_id = $effectiveCompanyId;

            // stage_id must resolve to a persisted TaskStage belonging to
            // the SAME project (and, transitively, the same company) as
            // this Task — a stage picker that isn't actually scoped to the
            // task's own project could otherwise attach a Task to a
            // Kanban column that belongs to a different project entirely
            // (#138 PR4 ola4A round 2 review).
            if ($task->stage_id !== null) {
                $stage = TaskStage::withoutGlobalScope(CompanyScope::class)->find($task->stage_id);

                if (! $stage) {
                    throw new AuthorizationException('The TaskStage could not be found.');
                }

                if ($task->project_id !== null && (int) $stage->project_id !== (int) $task->project_id) {
                    throw new AuthorizationException("The Task's stage_id does not belong to its Project.");
                }

                if ((int) $stage->company_id !== (int) $effectiveCompanyId) {
                    throw new AuthorizationException("The Task's stage_id does not belong to its company.");
                }
            }

            // parent_id must resolve to a persisted Task in the same
            // project and company — never itself (#138 PR4 ola4A round 2
            // review).
            if ($task->parent_id !== null) {
                if ($task->exists && (int) $task->parent_id === (int) $task->getKey()) {
                    throw new AuthorizationException('A Task cannot be its own parent.');
                }

                $parent = static::withoutGlobalScope(CompanyScope::class)->find($task->parent_id);

                if (! $parent) {
                    throw new AuthorizationException('The parent Task could not be found.');
                }

                if ($task->project_id !== null && $parent->project_id !== null && (int) $parent->project_id !== (int) $task->project_id) {
                    throw new AuthorizationException("The Task's parent_id does not belong to its Project.");
                }

                if ((int) $parent->company_id !== (int) $effectiveCompanyId) {
                    throw new AuthorizationException("The Task's parent_id does not belong to its company.");
                }
            }
        });

        static::updated(function ($task) {
            $task->timesheets()->update([
                'project_id' => $task->project_id,
                'partner_id' => $task->partner_id ?? $task->project?->partner_id,
                'company_id' => $task->company_id ?? $task->project?->company_id,
            ]);
        });
    }

    protected static function newFactory(): TaskFactory
    {
        return TaskFactory::new();
    }
}
