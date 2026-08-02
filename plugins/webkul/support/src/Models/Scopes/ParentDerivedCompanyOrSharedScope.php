<?php

namespace Webkul\Support\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Webkul\Support\Enums\CompanyContextMode;
use Webkul\Support\Services\CompanyContext;

/**
 * Same shape as Sale\ParentDerivedCompanyScope (#138 PR4 A4E/A4G), for a
 * child whose parent may itself be a company_or_shared row (Calendar,
 * #138 A4I) — the plain ParentDerivedCompanyScope filters the parent by
 * `whereIn('company_id', $companyIds)` only, which would hide every child
 * of a shared (company_id IS NULL) parent from everyone. This variant adds
 * `orWhereNull('company_id')` at each precedence level, mirroring
 * CompanyScope::applyCompanyFilter()'s own company_or_shared branch (ADR
 * 0007) but through the parent relation instead of a column on this
 * model's own table.
 *
 * Kept as its own local class rather than a flag/option on
 * ParentDerivedCompanyScope (#138 A4I, per orchestrator decision) — that
 * class and its sales/recruitments counterparts stay untouched, matching
 * this rollout's existing per-plugin duplication precedent.
 */
class ParentDerivedCompanyOrSharedScope implements Scope
{
    public function __construct(private readonly string $relation) {}

    /**
     * Reads the parent model off the relation's own query builder rather
     * than taking it as a constructor argument, so the soft-delete
     * decision cannot drift out of sync with the relation name.
     */
    private function withParentTrashed(Builder $query): Builder
    {
        return in_array(SoftDeletes::class, class_uses_recursive($query->getModel()), true)
            ? $query->withTrashed()
            : $query;
    }

    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if ($user && CompanyContext::current()) {
            throw new LogicException('An authenticated user is active while a CompanyContext is still open — these are mutually exclusive (ADR 0007).');
        }

        if ($user) {
            $companyIds = CompanyScope::allowedCompanyIds($user);

            if ($companyIds->isEmpty()) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->whereHas(
                $this->relation,
                fn ($query) => $this->withParentTrashed($query)
                    ->where(fn ($q) => $q->whereIn('company_id', $companyIds)->orWhereNull('company_id'))
            );

            return;
        }

        $context = CompanyContext::current();

        if ($context?->mode === CompanyContextMode::COMPANY) {
            $builder->whereHas(
                $this->relation,
                fn ($query) => $this->withParentTrashed($query)
                    ->where(fn ($q) => $q->where('company_id', $context->companyId)->orWhereNull('company_id'))
            );

            return;
        }

        if ($context?->mode === CompanyContextMode::ALL_COMPANIES || $context?->mode === CompanyContextMode::BOOTSTRAP) {
            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
