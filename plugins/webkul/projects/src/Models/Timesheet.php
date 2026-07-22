<?php

namespace Webkul\Project\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Analytic\Models\Record;
use Webkul\Support\Models\Scopes\CompanyScope;

class Timesheet extends Record
{
    protected static function boot()
    {
        parent::boot();

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

    /**
     * Overrides Record's generic resolution to derive (and cross-validate)
     * the full Timesheet → Task → Project → company graph instead of
     * trusting a plain company_id column. Task's own saving hook already
     * guarantees Task.company_id == its Project's company at the moment
     * Task is saved, but that Task row could since have been corrupted by
     * a bug or a manual DB edit — this re-verifies it directly rather than
     * assuming it still holds (#138 PR4 ola4A round 2 review).
     */
    protected function resolveEffectiveCompanyId(): int
    {
        if ($this->task_id === null) {
            // Only a row that was ALREADY orphaned before this exact save
            // (its Task was deleted via nullOnDelete, outside any Eloquent
            // hook) may keep saving without one, re-authorizing its own
            // already-persisted company_id — a brand new Timesheet with no
            // Task, or an existing one being manually detached, is rejected
            // outright (#138 PR4 ola4A round 2 review).
            $wasAlreadyOrphaned = $this->exists && $this->getOriginal('task_id') === null;

            if (! $wasAlreadyOrphaned) {
                throw new AuthorizationException(static::class.' requires a task_id.');
            }

            return parent::resolveEffectiveCompanyId();
        }

        $task = Task::withoutGlobalScope(CompanyScope::class)->find($this->task_id);

        if (! $task) {
            throw new AuthorizationException('The Task could not be found.');
        }

        if ($task->company_id === null) {
            throw new AuthorizationException('The Task has no company of its own to anchor to.');
        }

        if ($task->project_id !== null) {
            $project = Project::withoutGlobalScope(CompanyScope::class)->find($task->project_id);

            if ($project && (int) $task->company_id !== (int) $project->company_id) {
                throw new AuthorizationException("The Task's company does not match its Project's company.");
            }
        }

        if ($this->company_id !== null && (int) $this->company_id !== (int) $task->company_id) {
            throw new AuthorizationException("The company_id does not match the Task's company.");
        }

        if ($this->project_id !== null && $task->project_id !== null && (int) $this->project_id !== (int) $task->project_id) {
            throw new AuthorizationException("The Timesheet's project_id does not match its Task's project.");
        }

        CompanyScope::assertCanWriteCompany((int) $task->company_id);

        return (int) $task->company_id;
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
