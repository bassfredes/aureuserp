<?php

namespace Webkul\Sale\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Sale\Database\Factories\OrderTemplateProductFactory;
use Webkul\Sale\Models\Scopes\ParentDerivedCompanyScope;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Models\UOM;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

/**
 * Child of OrderTemplate (#138 PR4 A4H). It carries a company_id column
 * of its own, but the authoritative company is the parent template's:
 * exactly the shape already settled for Sale\OrderLine, which is why this
 * model is classified parent_scoped rather than given HasCompanyScope.
 * The FK is NOT NULL with cascadeOnDelete, so the row has no life of its
 * own outside its template.
 *
 * Its previous creating() hook filled four columns with "whatever exists
 * first":
 *
 *     company_id     ??= Company::first()?->id
 *     product_id     ??= Product::first()?->id
 *     product_uom_id ??= UOM::first()?->id
 *     creator_id     ??= Auth::id()
 *
 * A row created without those fields ended up anchored to an arbitrary
 * tenant, pointing at an arbitrary product that could belong to a third
 * company, under a template that could belong to a fourth. Only the
 * creator default was defensible. All three global fallbacks are gone:
 * the company comes from the template, the UOM from the product the line
 * actually describes, and the product is required rather than invented.
 */
class OrderTemplateProduct extends Model
{
    use HasFactory, ValidatesRelatedCompanyScope;

    protected $table = 'sales_order_template_products';

    protected $fillable = [
        'order_template_id',
        'company_id',
        'product_id',
        'product_uom_id',
        'creator_id',
        'name',
        'quantity',
        'display_type',
    ];

    public function orderTemplate(): BelongsTo
    {
        return $this->belongsTo(OrderTemplate::class, 'order_template_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UOM::class, 'product_uom_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * A section or a note is a layout row: it has a name and a position
     * and describes no product at all, so neither a product nor a unit of
     * measure may be invented for it. Every other row is a real template
     * line and must name the product it is a line FOR.
     */
    private function describesAProduct(): bool
    {
        return $this->display_type === null;
    }

    /**
     * Resolves and authorizes the parent template, then validates the
     * product against the company that template actually claims.
     *
     * The UOM is derived from the product rather than defaulted globally:
     * UOM is a global_reference with no company dimension, so the risk it
     * carried was coherence rather than tenancy, but a line quantified in
     * a unit belonging to some unrelated product is wrong all the same.
     * The lookup bypasses CompanyScope and includes trashed rows because
     * the product has already been validated on the line above; this is a
     * read of an attribute of an id that is known good, not a second
     * authorization.
     */
    private static function assertCoherentWithTemplate(self $line): int
    {
        $companyId = static::resolveEffectiveCompanyIdOrFail($line->order_template_id, OrderTemplate::class, $line->company_id, 'Order Template');

        $line->company_id = $companyId;

        if (! $line->describesAProduct()) {
            return $companyId;
        }

        if ($line->product_id === null) {
            throw new AuthorizationException('An OrderTemplateProduct line requires a product.');
        }

        static::assertRelatedBelongsToCompany($line->product_id, Product::class, 'Product', $companyId);

        $line->product_uom_id ??= Product::withoutGlobalScope(CompanyScope::class)
            ->withTrashed()
            ->find($line->product_id)?->uom_id;

        return $companyId;
    }

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
     * @param  int|string|null  $templateId
     */
    private static function authorizeTemplate($templateId): void
    {
        static::resolveEffectiveCompanyIdOrFail(
            $templateId === null ? null : (int) $templateId,
            OrderTemplate::class,
            null,
            'Order Template',
        );
    }

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope(new ParentDerivedCompanyScope('orderTemplate'));

        static::creating(function (self $line) {
            $companyId = static::assertCoherentWithTemplate($line);

            $line->creator_id ??= Auth::id();

            static::assertCreatorBelongsToCompany($line->creator_id, $companyId);
        });

        // The parent the row is ALREADY under is re-authorized on every
        // update, before anything else is considered (#138 PR4 A4H): a row
        // obtained through some technical bypass must not become movable
        // out of a company the actor cannot write to and into one it can,
        // just by pointing order_template_id somewhere legitimate. Only
        // once the current owner is authorized does the target template
        // get resolved and authorized in its turn, inside
        // assertCoherentWithTemplate().
        static::updating(function (self $line) {
            static::authorizeTemplate($line->getOriginal('order_template_id'));

            if ($line->isDirty('creator_id')) {
                throw new AuthorizationException("Changing this OrderTemplateProduct's creator is forbidden.");
            }

            if ($line->isDirty(['order_template_id', 'company_id', 'product_id', 'display_type'])) {
                static::assertCoherentWithTemplate($line);
            }
        });

        static::deleting(function (self $line) {
            static::authorizeTemplate($line->getOriginal('order_template_id') ?? $line->order_template_id);
        });
    }

    protected static function newFactory(): OrderTemplateProductFactory
    {
        return OrderTemplateProductFactory::new();
    }
}
