<?php

namespace Webkul\Employee\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Webkul\Support\Enums\CompanyContextMode;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;

/**
 * EmployeeSkill carries no company_id column of its own (#138 PR4 A4D,
 * employees family) — isolation derives entirely from the Employee it
 * belongs to. Same precedence CompanyScope::apply() implements (ADR 0007),
 * filtered through the employee() relation instead of a company_id column
 * — mirrors the BankAccountCompanyMembershipScope precedent (ola 4C) for a
 * non-standard tenant column, here via a belongsTo instead of a pivot.
 *
 * The employee relation constraint uses withTrashed(): a soft-deleted
 * Employee's own company_id must still govern its skills' visibility
 * consistently (same company still sees them, a different company still
 * cannot), rather than the default relation query silently excluding
 * trashed employees and making every one of their skills invisible to
 * everyone regardless of company — which would not be a stricter guarantee,
 * just an inconsistent one to reason about.
 */
class EmployeeSkillCompanyScope implements Scope
{
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

            $builder->whereHas('employee', fn ($query) => $query->withTrashed()->whereIn('company_id', $companyIds));

            return;
        }

        $context = CompanyContext::current();

        if ($context?->mode === CompanyContextMode::COMPANY) {
            $builder->whereHas('employee', fn ($query) => $query->withTrashed()->where('company_id', $context->companyId));

            return;
        }

        if ($context?->mode === CompanyContextMode::ALL_COMPANIES || $context?->mode === CompanyContextMode::BOOTSTRAP) {
            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
