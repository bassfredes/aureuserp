<?php

namespace Webkul\Partner\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Webkul\Support\Enums\CompanyContextMode;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;

/**
 * BankAccount carries no company_id column (approved contract, #138 PR4
 * ola4B) — isolation instead comes from the partners_bank_account_companies
 * membership pivot (BankAccount::enabledCompanies()). Same precedence
 * CompanyScope::apply() implements (ADR 0007), filtered through that pivot
 * instead of a company_id column — mirrors the LeaveAllocation precedent
 * for a non-standard tenant column (#138 PR4 ola4C).
 */
class BankAccountCompanyMembershipScope implements Scope
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

            $builder->whereHas('enabledCompanies', fn ($query) => $query->whereIn('companies.id', $companyIds));

            return;
        }

        $context = CompanyContext::current();

        if ($context?->mode === CompanyContextMode::COMPANY) {
            $builder->whereHas('enabledCompanies', fn ($query) => $query->where('companies.id', $context->companyId));

            return;
        }

        if ($context?->mode === CompanyContextMode::ALL_COMPANIES || $context?->mode === CompanyContextMode::BOOTSTRAP) {
            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
