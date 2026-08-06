<?php

namespace Webkul\Sale\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Account\Models\Journal;
use Webkul\Sale\Database\Factories\OrderTemplateFactory;
use Webkul\Sale\Enums\OrderDisplayType;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\HasStrictCompanyId;

/**
 * Strict company owner (#138 PR4 A4H): an OrderTemplate has a company_id
 * of its own, is referenced by sales_orders.sale_order_template_id, and
 * owns the OrderTemplateProduct rows that derive their company from it.
 *
 * No SoftDeletes here, so unlike Team (#138 PR4 A4G) there is no
 * restore/forceDelete lifecycle to guard: a plain deleting listener that
 * re-authorizes the persisted company is the whole delete contract, and
 * the children go with it through the FK's own cascadeOnDelete.
 */
class OrderTemplate extends Model implements Sortable
{
    use HasCompanyScope, HasFactory, HasStrictCompanyId, SortableTrait;

    protected $table = 'sales_order_templates';

    protected $fillable = [
        'sort',
        'company_id',
        'number_of_days',
        'creator_id',
        'name',
        'note',
        'journal_id',
        'is_active',
        'require_signature',
        'require_payment',
        'prepayment_percentage',
    ];

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function journal()
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    public function products()
    {
        return $this
            ->hasMany(OrderTemplateProduct::class, 'order_template_id')
            ->whereNull('display_type');
    }

    public function sections()
    {
        return $this
            ->hasMany(OrderTemplateProduct::class, 'order_template_id')
            ->where('display_type', OrderDisplayType::SECTION->value);
    }

    public function notes()
    {
        return $this
            ->hasMany(OrderTemplateProduct::class, 'order_template_id')
            ->where('display_type', OrderDisplayType::NOTE->value);
    }

    /**
     * A User has no company_id of its own, so membership is the only
     * meaningful company test: default_company_id plus the
     * allowedCompanies() pivot, exactly what CompanyScope::allowedCompanyIds()
     * reads. A nonexistent id fails closed rather than no-opping (#138
     * A4F review 4827999112).
     */
    private static function assertCreatorBelongsToCompany(?int $creatorId, ?int $companyId): void
    {
        if ($creatorId === null) {
            return;
        }

        $creator = User::find($creatorId);

        if (! $creator) {
            throw new AuthorizationException('The creator does not exist.');
        }

        if ($companyId === null || ! CompanyScope::allowedCompanyIds($creator)->contains((int) $companyId)) {
            throw new AuthorizationException('The creator has no membership in this company.');
        }
    }

    /**
     * Journal is itself a strict company owner (HasCompanyScope plus
     * HasStrictCompanyId), but sales_order_templates.journal_id is a bare
     * `integer` column: the migration never declared a foreign key, so
     * there is no referential integrity behind it either (#138 PR4 A4H).
     * A stale or foreign id is therefore entirely plausible, and both are
     * rejected here. Changing the schema is out of scope for this
     * rollout, so this application-level check is the only guard the
     * relation has.
     *
     * Resolved with CompanyScope bypassed on purpose: a Journal the actor
     * cannot see must be caught as a mismatch, not slip through as "not
     * found, so nothing to compare".
     */
    private static function assertJournalBelongsToCompany(?int $journalId, ?int $companyId): void
    {
        if ($journalId === null) {
            return;
        }

        $journal = Journal::withoutGlobalScope(CompanyScope::class)->find($journalId);

        if (! $journal) {
            throw new AuthorizationException('The related Journal could not be found.');
        }

        if ($companyId === null || $journal->company_id === null || (int) $journal->company_id !== (int) $companyId) {
            throw new AuthorizationException('The related Journal belongs to a different company.');
        }
    }

    protected static function boot()
    {
        parent::boot();

        // Fires after HasStrictCompanyId's own `saving` listener has
        // already resolved and authorized company_id, so it is safe to
        // trust on both create and update.
        //
        // creator_id follows the contract settled in A4F (reviews
        // 4827999112 and 4830829763): defaulted on create, validated when
        // explicit, and immutable afterwards via isDirty() without
        // conditioning on the original value, so a row whose creator was
        // blanked by nullOnDelete() cannot later be claimed.
        //
        // journal_id is re-validated on EVERY save rather than only when
        // dirty: the company is what the journal is checked against, so
        // an unchanged journal still has to be re-checked whenever the
        // row is written.
        static::saving(function (self $orderTemplate) {
            if (! $orderTemplate->exists) {
                $orderTemplate->creator_id ??= Auth::id();

                static::assertCreatorBelongsToCompany($orderTemplate->creator_id, $orderTemplate->company_id);
            } elseif ($orderTemplate->isDirty('creator_id')) {
                throw new AuthorizationException("Changing this OrderTemplate's creator is forbidden.");
            }

            static::assertJournalBelongsToCompany($orderTemplate->journal_id, $orderTemplate->company_id);
        });

        // HasStrictCompanyId only guards saving, and HasCompanyScope's
        // read filter alone does not imply it: a forAllCompanies() or
        // system-context caller can still load a cross-company row.
        static::deleting(function (self $orderTemplate) {
            CompanyScope::assertCanWriteCompany((int) $orderTemplate->company_id);
        });
    }

    /**
     * Laravel's default factory resolution looks for
     * Database\Factories\Webkul\Sale\Models\..., which does not exist in
     * this plugin layout (#138 A4F).
     */
    protected static function newFactory(): OrderTemplateFactory
    {
        return OrderTemplateFactory::new();
    }
}
