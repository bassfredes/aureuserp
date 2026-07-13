<?php

namespace Webkul\Support\Models\Scopes;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Webkul\Support\Models\Contracts\IncludesSharedCompanyRows;

class CompanyScope implements Scope
{
    /**
     * The companies a user is allowed to operate on (default company +
     * explicitly allowed companies). Shared by CompanyScope itself and by
     * any write path (controllers, form requests) that must validate a
     * client-submitted company_id/parent company against the same set this
     * scope uses for reads — reads and writes must agree on "allowed".
     */
    public static function allowedCompanyIds(?Authenticatable $user): Collection
    {
        if (! $user) {
            return collect();
        }

        return $user->allowedCompanies()
            ->pluck('companies.id')
            ->push($user->default_company_id)
            ->unique()
            ->filter()
            ->values();
    }

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

        $companyIds = static::allowedCompanyIds($user);

        // An authenticated user with no company association sees nothing by
        // default (fail closed) — the only sanctioned way to see across
        // companies is the explicit, audited HasCompanyScope::forAllCompanies(),
        // not an implicit exception baked into this scope. This check stays
        // ahead of the shared-rows branch below: a companyless user must not
        // see shared rows either, only forAllCompanies() bypasses isolation.
        if ($companyIds->isEmpty()) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $column = $model->qualifyColumn('company_id');

        // strict_company (default): company_id IN (allowed companies) only.
        if (! $model instanceof IncludesSharedCompanyRows) {
            $builder->whereIn($column, $companyIds);

            return;
        }

        // company_or_shared: the model opted in via IncludesSharedCompanyRows
        // (see ADR 0007). company_id IS NULL rows are system-managed shared
        // references (e.g. Location's global Vendors/Customers locations),
        // not incomplete records — visible alongside the user's own
        // companies, never as a way for a companyless user to see anything.
        $builder->where(function (Builder $query) use ($column, $companyIds): void {
            $query->whereIn($column, $companyIds)
                ->orWhereNull($column);
        });
    }
}
