<?php

namespace Webkul\Recruitment\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Webkul\Support\Enums\CompanyContextMode;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;

/**
 * Shared by every recruitments child/pivot with no company_id column of
 * its own (ApplicantApplicantCategory, ApplicantInterviewer,
 * CandidateApplicantCategory, CandidateSkill, JobPositionInterviewer,
 * StageJob — #138 PR4 A4E) — same precedence CompanyScope::apply()
 * implements (ADR 0007), filtered through the given parent relation
 * instead of a company_id column, parametrized by relation name rather
 * than duplicated six times verbatim (mirrors the
 * EmployeeSkillCompanyScope precedent from the employees family, ola
 * A4D, generalized here since every one of these six needs the exact
 * same logic against a different parent relation).
 *
 * withTrashed() on the parent relation: a soft-deleted parent's own
 * company_id must still consistently govern its children's visibility
 * (same company still sees them, a different company still cannot),
 * rather than the relation query silently excluding a trashed parent and
 * making every one of its children invisible to everyone regardless of
 * company.
 */
class ParentDerivedCompanyScope implements Scope
{
    public function __construct(private readonly string $relation) {}

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

            $builder->whereHas($this->relation, fn ($query) => $query->withTrashed()->whereIn('company_id', $companyIds));

            return;
        }

        $context = CompanyContext::current();

        if ($context?->mode === CompanyContextMode::COMPANY) {
            $builder->whereHas($this->relation, fn ($query) => $query->withTrashed()->where('company_id', $context->companyId));

            return;
        }

        if ($context?->mode === CompanyContextMode::ALL_COMPANIES || $context?->mode === CompanyContextMode::BOOTSTRAP) {
            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
