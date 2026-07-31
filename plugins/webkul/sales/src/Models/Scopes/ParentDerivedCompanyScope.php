<?php

namespace Webkul\Sale\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Webkul\Support\Enums\CompanyContextMode;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;

/**
 * For a pivot with no company_id column of its own
 * (AdvancedPaymentInvoiceOrderSale — #138 A4F) — same precedence
 * CompanyScope::apply() implements (ADR 0007), filtered through the given
 * parent relation instead of a company_id column. Same generalized shape
 * as the recruitments family's own ParentDerivedCompanyScope (#138 PR4
 * A4E) — kept as a local duplicate here rather than a shared cross-plugin
 * dependency, matching this rollout's existing per-plugin duplication
 * precedent (see e.g. GuardsCompanyLifecycleOnSoftDelete in both
 * recruitments and employees).
 *
 * No withTrashed() on the parent relation here, unlike the recruitments
 * original: AdvancedPaymentInvoice does not use SoftDeletes, so the
 * parent relation query has no such scope to call.
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

            $builder->whereHas($this->relation, fn ($query) => $query->whereIn('company_id', $companyIds));

            return;
        }

        $context = CompanyContext::current();

        if ($context?->mode === CompanyContextMode::COMPANY) {
            $builder->whereHas($this->relation, fn ($query) => $query->where('company_id', $context->companyId));

            return;
        }

        if ($context?->mode === CompanyContextMode::ALL_COMPANIES || $context?->mode === CompanyContextMode::BOOTSTRAP) {
            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
