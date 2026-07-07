<?php

namespace Webkul\Support\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class CompanyScope implements Scope
{
    /**
     * Filter the query to only the companies the authenticated user is
     * allowed to operate on (default company + explicitly allowed companies).
     *
     * No authenticated user (console, queue, seeders) or a user with no
     * company association: no filtering is applied.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user) {
            return;
        }

        $companyIds = $user->allowedCompanies()
            ->pluck('companies.id')
            ->push($user->default_company_id)
            ->unique()
            ->filter();

        // An authenticated user with no company association sees nothing by
        // default (fail closed) — the only sanctioned way to see across
        // companies is the explicit, audited HasCompanyScope::forAllCompanies(),
        // not an implicit exception baked into this scope.
        if ($companyIds->isEmpty()) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->whereIn($model->getTable().'.company_id', $companyIds);
    }
}
