<?php

namespace Webkul\Chatter\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Webkul\Support\Enums\CompanyContextMode;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;
use Webkul\Support\Traits\HasCompanyScope;

/**
 * Chatter (Message/Attachment/Follower) is polymorphic across at least 19
 * HasChatter-consuming models that are NOT homogeneous (#138 PR4 chatter
 * gap, 2026-08-03 Codex adversarial review): Company is its own
 * root_company_entity, Partner is a global_party_identity with no company
 * scope of its own, and everything else (Order/Task/Project/Move/...) is
 * strict_company or company_or_shared via HasCompanyScope. This trait is
 * the single place that decides "what company does this chatter row belong
 * to", replacing the previous behavior of deriving company_id from the
 * ACTING USER (HasChatter::addMessage() used to read
 * $user?->defaultCompany?->id) — a caller acting on behalf of company A
 * could label a message on a company B record as belonging to A, or an
 * actor whose own default company differs from the record they are
 * commenting on could mislabel it entirely. Company must always come from
 * the record being commented on/followed, never from who is doing it.
 *
 * Fail-closed by design: the only case producing a null company_id is a
 * HasCompanyScope owner that is legitimately company_or_shared with its own
 * company_id already null (a real, existing shared record) — never a
 * silent fallback for an owner whose company could not be determined.
 */
trait ResolvesChatterCompany
{
    /**
     * @return int|null Null only when $owner uses HasCompanyScope and is
     *                  itself a legitimate company_or_shared row with a
     *                  null company_id — never as a fallback for an
     *                  unresolved owner.
     *
     * @throws AuthorizationException when $owner carries no company scope
     *                                of its own (e.g. Partner) and neither
     *                                an active company-mode CompanyContext
     *                                nor an authenticated user with a
     *                                default company is available to
     *                                anchor it to.
     */
    protected static function resolveChatterCompanyId(Model $owner): ?int
    {
        if ($owner instanceof Company) {
            return (int) $owner->getKey();
        }

        if (in_array(HasCompanyScope::class, class_uses_recursive($owner), true)) {
            $companyId = $owner->getAttribute('company_id');

            return $companyId !== null ? (int) $companyId : null;
        }

        // $owner is a global_party_identity (Partner) or any other model
        // with no HasCompanyScope contract of its own — its company must
        // come from the acting context, never inferred from the owner
        // itself, and never defaulted silently.
        $context = CompanyContext::current();

        if ($context?->mode === CompanyContextMode::COMPANY && $context->companyId !== null) {
            return (int) $context->companyId;
        }

        $defaultCompanyId = Auth::user()?->default_company_id;

        if ($defaultCompanyId !== null) {
            return (int) $defaultCompanyId;
        }

        throw new AuthorizationException(sprintf(
            'Cannot resolve a company for a chatter record owned by %s (id %s): the owner carries no company scope of its own, and no company-mode CompanyContext or authenticated user default company is active.',
            get_class($owner),
            $owner->getKey() ?? 'unknown',
        ));
    }

    /**
     * Resolves the real polymorphic owner behind $typeColumn/$idColumn
     * directly from the DB, bypassing both CompanyScope (the derivation
     * must see the owner's true company_id even if the acting user/context
     * cannot see that company — same reasoning as
     * ValidatesRelatedCompanyScope) and, for soft-deletable owners, the
     * default trashed exclusion (a message on a since-archived record must
     * still resolve, same precedent). Returns null when the columns are
     * empty or the referenced class/row cannot be found at all.
     */
    protected static function resolveChatterOwnerFromPolymorphicColumns(Model $record, string $typeColumn, string $idColumn): ?Model
    {
        $type = $record->getAttribute($typeColumn);
        $id = $record->getAttribute($idColumn);

        if (! $type || ! $id) {
            return null;
        }

        $ownerClass = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($ownerClass) || ! is_subclass_of($ownerClass, Model::class)) {
            return null;
        }

        $query = $ownerClass::withoutGlobalScope(CompanyScope::class);

        if (in_array(SoftDeletes::class, class_uses_recursive($ownerClass), true)) {
            $query = $query->withTrashed();
        }

        return $query->find($id);
    }

    /**
     * Shared saving-hook body for Message/Attachment (owner relation
     * 'messageable') and Follower (owner relation 'followable'). Mirrors
     * HasStrictCompanyId's single `saving` listener shape rather than
     * separate creating/updating listeners: fires before either, and
     * re-derives/re-authorizes company_id on every save (not only when
     * dirty), because read isolation (CompanyScope hiding a row) is not
     * the same guarantee as write authorization (#138 review precedent).
     *
     * - On create: an explicit company_id is accepted only as a
     *   cross-check against the derived value — never as the source of
     *   truth — and rejected outright on mismatch rather than silently
     *   overwritten.
     * - On update: the owner (messageable/followable identity) can never
     *   change; re-derives company_id from the persisted owner every time
     *   and rejects if it now disagrees with the persisted company_id
     *   (the owner's own company must never have moved out from under an
     *   existing chatter row without going through this same guard).
     *
     * Returns the resolved owner Model so callers (Attachment's
     * message_id consistency check) can reuse it without a second query.
     */
    protected static function applyChatterOwnerCompany(Model $record, string $ownerRelation): Model
    {
        $typeColumn = $ownerRelation.'_type';
        $idColumn = $ownerRelation.'_id';

        if ($record->exists) {
            $originalType = $record->getOriginal($typeColumn);
            $originalId = $record->getOriginal($idColumn);

            if ($originalType !== null && (
                $originalType !== $record->getAttribute($typeColumn)
                || (int) $originalId !== (int) $record->getAttribute($idColumn)
            )) {
                throw new AuthorizationException(sprintf(
                    'Changing the %s of an existing %s is forbidden — create a new one instead.',
                    $ownerRelation,
                    class_basename($record),
                ));
            }
        }

        $owner = static::resolveChatterOwnerFromPolymorphicColumns($record, $typeColumn, $idColumn);

        if (! $owner instanceof Model) {
            throw new AuthorizationException(sprintf(
                'A %s must reference an existing %s owner before it can be saved.',
                class_basename($record),
                $ownerRelation,
            ));
        }

        $resolved = static::resolveChatterCompanyId($owner);

        // Baseline is the value to cross-check against: the caller's
        // explicit input on create, or the row's own persisted value on
        // update (never the possibly-tampered in-memory value).
        $baseline = $record->exists
            ? $record->getOriginal('company_id')
            : $record->getAttribute('company_id');

        $baseline = $baseline !== null ? (int) $baseline : null;

        if ($baseline !== null && $baseline !== $resolved) {
            throw new AuthorizationException(sprintf(
                'The company_id of this %s does not match the company derived from its %s owner.',
                class_basename($record),
                $ownerRelation,
            ));
        }

        $record->company_id = $resolved;

        return $owner;
    }
}
