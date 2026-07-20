<?php

namespace Webkul\Project\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Project\Models\Milestone;
use Webkul\Project\Models\Project;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Scopes\CompanyScope;

class MilestonePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_project_milestone');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Milestone $milestone): bool
    {
        return $user->can('view_project_milestone') && $this->belongsToAllowedCompany($user, $milestone);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_project_milestone');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Milestone $milestone): bool
    {
        return $user->can('update_project_milestone') && $this->belongsToAllowedCompany($user, $milestone);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Milestone $milestone): bool
    {
        return $user->can('delete_project_milestone') && $this->belongsToAllowedCompany($user, $milestone);
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_project_milestone');
    }

    /**
     * A general ability string is not authorization for a specific record
     * (#138 PR4 ola4A): Milestone carries no company_id of its own, so this
     * re-derives its effective company from the PERSISTED Project (never a
     * dirty in-memory relation on $milestone) and checks it against the
     * acting user's own allowed companies — independent of, and in addition
     * to, CompanyScope's read-side global scope. A route/controller that
     * resolves the record via an unscoped query must still be caught here.
     */
    private function belongsToAllowedCompany(User $user, Milestone $milestone): bool
    {
        $project = Project::withoutGlobalScope(CompanyScope::class)->find($milestone->project_id);

        if (! $project || $project->company_id === null) {
            return false;
        }

        return CompanyScope::allowedCompanyIds($user)->contains($project->company_id);
    }
}
