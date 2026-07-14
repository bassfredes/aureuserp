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
        if (! static::actorSupportsCompanyMembership($user)) {
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
     * Company isolation only applies to actors that expose an operative
     * company-membership contract (allowedCompanies() + default_company_id)
     * — today, Webkul\Security\Models\User. Other Authenticatables (e.g.
     * Webkul\Partner\Models\Partner, authenticated under the `customer`
     * guard for the purchases/blogs/website vendor portals) carry no
     * company membership at all. A concrete instanceof check would couple
     * this shared scope to one caller's model; method_exists() is the
     * minimal capability check, and treating an unsupported actor as "zero
     * companies" routes it through apply()'s existing fail-closed branch
     * instead of a fatal error from calling a method it doesn't have.
     */
    private static function actorSupportsCompanyMembership(?Authenticatable $user): bool
    {
        return $user !== null && method_exists($user, 'allowedCompanies');
    }

    /**
     * Filter the query to only the companies the authenticated user is
     * allowed to operate on (default company + explicitly allowed companies).
     *
     * No authenticated actor at all (console, queue, seeders, installer):
     * no filtering is applied — there is no user to scope against. An
     * authenticated actor with no company association (no default_company_id
     * and no allowedCompanies() rows, or one that doesn't support company
     * membership at all — see actorSupportsCompanyMembership()) is a
     * different case and is NOT left unfiltered: it fails closed to
     * `1 = 0` below, seeing nothing by default.
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
