<?php

namespace Webkul\Sale\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\SoftDeletes;
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
 * withTrashed() is applied to the parent relation only when the parent
 * really uses SoftDeletes. The recruitments original applies it
 * unconditionally because every parent there is soft-deletable; the two
 * parents here differ. AdvancedPaymentInvoice (#138 A4F) is not
 * soft-deletable, and calling withTrashed() on its relation query would
 * throw. Team (#138 A4G) is, and omitting it would make every membership
 * of a soft-deleted Team invisible to everyone regardless of company,
 * instead of keeping the trashed parent's own company_id governing its
 * children's visibility.
 */
class ParentDerivedCompanyScope implements Scope
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

            $builder->whereHas($this->relation, fn ($query) => $this->withParentTrashed($query)->whereIn('company_id', $companyIds));

            return;
        }

        $context = CompanyContext::current();

        if ($context?->mode === CompanyContextMode::COMPANY) {
            $builder->whereHas($this->relation, fn ($query) => $this->withParentTrashed($query)->where('company_id', $context->companyId));

            return;
        }

        if ($context?->mode === CompanyContextMode::ALL_COMPANIES || $context?->mode === CompanyContextMode::BOOTSTRAP) {
            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
