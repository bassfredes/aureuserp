<?php

namespace Webkul\Support\Models\Scopes;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Webkul\Support\Enums\CompanyContextMode;
use Webkul\Support\Models\Contracts\IncludesSharedCompanyRows;
use Webkul\Support\Services\CompanyContext;

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
     * Precedence (ADR 0007, "Transición e integración futura con
     * CompanyScope"):
     *
     * 1. Authenticated user           → allowedCompanyIds() filter, unchanged.
     * 2. No user + CompanyContext::current():
     *    - company                    → exact filter on that one company.
     *    - all_companies | bootstrap  → no filter, explicit system bypass.
     * 3. No user + no active context  → fail closed, `1 = 0`.
     *
     * Point 3 replaces the pre-PR-2B behavior (`if (! $user) { return; }`,
     * unfiltered). Every no-user consumer that legitimately needs to read
     * these models must now open a CompanyContext explicitly — an absent
     * context is no longer implicit global access.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        // CompanyContext::run() already refuses to open a context for an
        // authenticated actor, but that only guards the moment the context
        // is opened — if a callback authenticates a user WHILE its context
        // is still active (e.g. an unexpected Auth::login() deeper in the
        // call stack), the two must never silently coexist. The precedence
        // matrix (ADR 0007) declares an authenticated actor with any active
        // system context an invalid state, not "user wins" — fail loud
        // here instead of silently prioritizing the user.
        if ($user && CompanyContext::current()) {
            throw new LogicException('An authenticated user is active while a CompanyContext is still open — these are mutually exclusive (ADR 0007).');
        }

        if ($user) {
            $companyIds = static::allowedCompanyIds($user);

            // An authenticated user with no company association sees nothing
            // by default (fail closed) — the only sanctioned way to see
            // across companies is the explicit, audited
            // HasCompanyScope::forAllCompanies(), not an implicit exception
            // baked into this scope. This check stays ahead of the
            // shared-rows branch below: a companyless user must not see
            // shared rows either, only forAllCompanies() bypasses isolation.
            if ($companyIds->isEmpty()) {
                $builder->whereRaw('1 = 0');

                return;
            }

            static::applyCompanyFilter($builder, $model, $companyIds);

            return;
        }

        $context = CompanyContext::current();

        if ($context?->mode === CompanyContextMode::COMPANY) {
            static::applyCompanyFilter($builder, $model, collect([$context->companyId]));

            return;
        }

        // all_companies / bootstrap: explicit, audited system-wide bypass —
        // no filter at all, same as forAllCompanies() but for a no-user
        // process instead of a super_admin actor.
        if ($context?->mode === CompanyContextMode::ALL_COMPANIES || $context?->mode === CompanyContextMode::BOOTSTRAP) {
            return;
        }

        // No user, no context: fail closed. Sees nothing by default.
        $builder->whereRaw('1 = 0');
    }

    /**
     * Shared by the authenticated-user branch (possibly several allowed
     * companies) and the company-mode system-context branch (always
     * exactly one) — both apply the same strict_company/company_or_shared
     * distinction, just over a different-sized set of ids.
     */
    private static function applyCompanyFilter(Builder $builder, Model $model, Collection $companyIds): void
    {
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
