<?php

namespace Webkul\Purchase\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Purchase\Models\ProductSupplier;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Scopes\CompanyScope;

class ProductSupplierPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_purchase_vendor::price');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ProductSupplier $productSupplier): bool
    {
        return $user->can('view_purchase_vendor::price')
            && $this->belongsToAllowedCompany($user, $productSupplier);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_purchase_vendor::price');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ProductSupplier $productSupplier): bool
    {
        return $user->can('update_purchase_vendor::price')
            && $this->belongsToAllowedCompany($user, $productSupplier);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ProductSupplier $productSupplier): bool
    {
        return $user->can('delete_purchase_vendor::price')
            && $this->belongsToAllowedCompany($user, $productSupplier);
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_purchase_vendor::price');
    }

    /**
     * ProductSupplier has no HasCompanyScope (products tramo of #137 still
     * pending), so nothing hides a cross-company row before it reaches this
     * policy — view/update/delete must check company access explicitly.
     * Covers the API controller's Gate::authorize() calls and Filament's
     * automatic policy checks on EditAction/ViewAction/DeleteAction, including
     * the ManageVendors relation-manager page, which all share this policy.
     * A null company_id (undecided shared/legacy semantics for this table)
     * is denied, not treated as accessible to everyone.
     */
    private function belongsToAllowedCompany(User $user, ProductSupplier $productSupplier): bool
    {
        return CompanyScope::allowedCompanyIds($user)->contains($productSupplier->company_id);
    }
}
