<?php

namespace Webkul\Sale\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Sale\Database\Factories\OrderTemplateProductFactory;
use Webkul\Sale\Enums\OrderDisplayType;
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
     * display_type is a closed set. NULL means a real template line; the
     * two OrderDisplayType cases mean a layout row — a row that has a name
     * and a position and describes no product at all, so neither a product
     * nor a unit of measure may be invented for it. There is no third kind
     * of row, and this returns the canonical string form of a recognized
     * value so the caller can branch on it.
     *
     * The first version of this guard asked only "is it a section or a
     * note?" and treated every other value, invented ones included, as a
     * real line. That was fail-open in the one direction that mattered: an
     * unknown display_type paired with a valid, active, same-company
     * product satisfied every remaining check and was persisted, so a
     * caller could write rows whose layout semantics nothing in this
     * codebase can read back — OrderTemplate::lines(), sections() and
     * notes() match on NULL, 'section' and 'note' respectively and would
     * all silently skip such a row. The test is now positive: the value
     * must be one of the three recognized ones, and an unrecognized one is
     * itself the rejection (#138 A4H correction).
     */
    private static function assertSupportedDisplayType(self $line): ?string
    {
        $displayType = $line->display_type;

        if ($displayType instanceof OrderDisplayType) {
            return $displayType->value;
        }

        if ($displayType === null) {
            return null;
        }

        $supported = [
            OrderDisplayType::SECTION->value,
            OrderDisplayType::NOTE->value,
        ];

        if (! is_string($displayType) || ! in_array($displayType, $supported, true)) {
            throw new AuthorizationException('The display_type of an OrderTemplateProduct line is not supported.');
        }

        return $displayType;
    }

    /**
     * Resolves and authorizes the parent template, then validates the
     * product against the company that template actually claims.
     *
     * The Product lookup is done locally rather than through the shared
     * assertRelatedBelongsToCompany() helper (#138 A4H correction): that
     * helper only compares company_id and silently no-ops on a missing or
     * trashed-and-not-caught row, which is right for its own generic
     * contract but not strict enough here. A nonexistent product_id must
     * be rejected by this application-level guard instead of falling
     * through to the database FK, and a soft-deleted product must be
     * rejected even when it belongs to the correct company — a deleted
     * product is not a valid line target regardless of tenancy.
     *
     * The UOM is derived from the same already-resolved, already-verified
     * Product rather than defaulted globally or looked up a second time:
     * UOM is a global_reference with no company dimension, so the risk it
     * carried was coherence rather than tenancy, but a line quantified in
     * a unit belonging to some unrelated product is wrong all the same.
     *
     * A layout row (section/note) is symmetric: it must carry NEITHER a
     * product NOR a unit of measure, on create and on update alike — this
     * is checked explicitly rather than silently cleared, so a caller that
     * pairs a layout display_type with a leftover/injected product_id or
     * product_uom_id is rejected instead of quietly "fixed".
     *
     * display_type is validated first, before the row is classified as a
     * real line or as layout and before company_id is touched at all: an
     * unrecognized value has no branch to fall into, so nothing about the
     * row — not even its resolved company — is decided on its behalf.
     */
    private static function assertCoherentWithTemplate(self $line): int
    {
        $displayType = static::assertSupportedDisplayType($line);

        $companyId = static::resolveEffectiveCompanyIdOrFail($line->order_template_id, OrderTemplate::class, $line->company_id, 'Order Template');

        $line->company_id = $companyId;

        if ($displayType !== null) {
            if ($line->product_id !== null || $line->product_uom_id !== null) {
                throw new AuthorizationException('A section or a note line must not reference a product or a unit of measure.');
            }

            return $companyId;
        }

        if ($line->product_id === null) {
            throw new AuthorizationException('An OrderTemplateProduct line requires a product.');
        }

        // withoutGlobalScope: the referenced Product must be resolvable even
        // if the acting actor cannot see it under CompanyScope, so a mismatch
        // is reported as "different company" rather than "not found" —
        // withTrashed() likewise so a soft-deleted Product is still caught.
        $product = Product::withoutGlobalScope(CompanyScope::class)
            ->withTrashed()
            ->find($line->product_id);

        if (! $product) {
            throw new AuthorizationException('The related Product could not be found.');
        }

        if ($product->trashed()) {
            throw new AuthorizationException('The related Product has been deleted.');
        }

        if ($product->company_id === null || (int) $product->company_id !== $companyId) {
            throw new AuthorizationException('The related Product belongs to a different company.');
        }

        $line->product_uom_id ??= $product->uom_id;

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

            if ($line->isDirty(['order_template_id', 'company_id', 'product_id', 'product_uom_id', 'display_type'])) {
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
