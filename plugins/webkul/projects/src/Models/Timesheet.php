<?php

namespace Webkul\Project\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Analytic\Models\Record;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

class Timesheet extends Record
{
    use HasCompanyScope, ValidatesRelatedCompanyScope;

    protected static function boot()
    {
        parent::boot();

        static::saving(function (self $timesheet): void {
            // The effective company is derived from the persisted Task
            // (never a dirty in-memory relation) — Task's own saving hook
            // already guarantees Task.company_id == its Project's company,
            // so chaining through Task is enough to validate the full
            // Timesheet → Task → Project → company graph (#138 PR4 ola4A).
            // A task_id-less Timesheet (orphaned after its Task was
            // deleted via nullOnDelete) keeps re-authorizing its own,
            // already-persisted company_id instead of failing every future
            // save — same fallback shape as Task's own no-project branch.
            if ($timesheet->task_id !== null) {
                $effectiveCompanyId = static::resolveEffectiveCompanyIdOrFail($timesheet->task_id, Task::class, $timesheet->company_id, 'Task');
            } else {
                $effectiveCompanyId = $timesheet->company_id ?? Auth::user()?->default_company_id;

                if ($effectiveCompanyId === null) {
                    throw new AuthorizationException(static::class.' requires a company_id and none could be resolved from the acting user.');
                }

                CompanyScope::assertCanWriteCompany((int) $effectiveCompanyId);
            }

            // A task_id reassignment (alone, or paired with a matching
            // explicit company_id) that would move an already-persisted
            // Timesheet to a different company is rejected outright — same
            // "archive and recreate instead" rule as Project/Task/TaskStage
            // (#138 PR4 ola4A).
            $originalCompanyId = $timesheet->exists ? $timesheet->getOriginal('company_id') : null;

            if ($originalCompanyId !== null && (int) $originalCompanyId !== (int) $effectiveCompanyId) {
                throw new AuthorizationException('Changing the company of this Timesheet (via task_id or company_id) is forbidden — archive it and create a new one instead.');
            }

            $timesheet->company_id = $effectiveCompanyId;

            // The Timesheet's own project_id (if present) must agree with
            // its Task's persisted project_id — a Task belonging to Project
            // A referenced alongside an explicit project_id for Project B
            // is a spoofed/inconsistent graph, not merely a company
            // mismatch (#138 PR4 ola4A).
            if ($timesheet->project_id !== null && $timesheet->task_id !== null) {
                $task = Task::withoutGlobalScope(CompanyScope::class)->find($timesheet->task_id);

                if ($task && (int) $task->project_id !== (int) $timesheet->project_id) {
                    throw new AuthorizationException("The Timesheet's project_id does not match its Task's project.");
                }
            }
        });

        static::created(function ($timesheet) {
            $timesheet->updateTaskTimes();
        });

        static::updated(function ($timesheet) {
            $timesheet->updateTaskTimes();
        });

        static::deleted(function ($timesheet) {
            $timesheet->updateTaskTimes();
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function updateTaskTimes()
    {
        if (! $this->task) {
            return;
        }

        $task = $this->task;

        $effectiveHours = $hoursSpent = $task->timesheets()->sum('unit_amount');

        if ($task->subTasks->count()) {
            $hoursSpent += $task->subTasks->reduce(function ($carry, $subTask) {
                return $carry + $subTask->timesheets()->sum('unit_amount');
            }, 0);
        }

        $task->update([
            'total_hours_spent' => $hoursSpent,
            'effective_hours'   => $effectiveHours,
            'overtime'          => $hoursSpent > $task->allocated_hours ? $hoursSpent - $task->allocated_hours : 0,
            'remaining_hours'   => $task->allocated_hours - $hoursSpent,
            'progress'          => $task->allocated_hours ? ($hoursSpent / $task->allocated_hours) * 100 : 0,
        ]);

        if ($parentTask = $task->parent) {
            $parentEffectiveHours = $parentHoursSpent = $parentTask->timesheets()->sum('unit_amount');

            $parentHoursSpent += $parentTask->subTasks->reduce(function ($carry, $subTask) {
                return $carry + $subTask->timesheets()->sum('unit_amount');
            }, 0);

            $parentTask->update([
                'total_hours_spent'       => $parentHoursSpent,
                'effective_hours'         => $parentEffectiveHours,
                'subtask_effective_hours' => $parentTask->subTasks->sum('effective_hours'),
                'overtime'                => $parentHoursSpent > $parentTask->allocated_hours ? $parentHoursSpent - $parentTask->allocated_hours : 0,
                'remaining_hours'         => $parentTask->allocated_hours - $parentHoursSpent,
                'progress'                => $parentTask->allocated_hours ? ($parentHoursSpent / $parentTask->allocated_hours) * 100 : 0,
            ]);
        }
    }

    public function updateTaskTimesOld()
    {
        if (! $this->task) {
            return;
        }

        $totalTime = $this->task->timesheets()->sum('unit_amount');

        $this->task->update([
            'total_hours_spent' => $totalTime,
            'effective_hours'   => $totalTime,
            'overtime'          => $totalTime > $this->task->allocated_hours ? $totalTime - $this->task->allocated_hours : 0,
            'remaining_hours'   => $this->task->allocated_hours - $totalTime,
            'progress'          => $this->task->allocated_hours ? ($totalTime / $this->task->allocated_hours) * 100 : 0,
        ]);
    }
}
