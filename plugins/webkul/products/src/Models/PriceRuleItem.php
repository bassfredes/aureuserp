<?php

namespace Webkul\Product\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Product\Database\Factories\PriceRuleItemFactory;
use Webkul\Product\Enums\PriceRuleApplyTo;
use Webkul\Product\Enums\PriceRuleBase;
use Webkul\Product\Enums\PriceRuleType;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\HasCompanyScope;

class PriceRuleItem extends Model
{
    use HasCompanyScope, HasFactory;

    protected $table = 'products_price_rule_items';

    protected $fillable = [
        'apply_to',
        'display_apply_to',
        'base',
        'type',
        'min_quantity',
        'fixed_price',
        'price_discount',
        'price_round',
        'price_surcharge',
        'price_markup',
        'price_min_margin',
        'percent_price',
        'starts_at',
        'ends_at',
        'price_rule_id',
        'base_price_rule_id',
        'currency_id',
        'product_id',
        'category_id',
        'company_id',
        'creator_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
        'apply_to'  => PriceRuleApplyTo::class,
        'base'      => PriceRuleBase::class,
        'type'      => PriceRuleType::class,
    ];

    public function priceRule(): BelongsTo
    {
        return $this->belongsTo(PriceRule::class);
    }

    public function basePriceRule(): BelongsTo
    {
        return $this->belongsTo(PriceRule::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): PriceRuleItemFactory
    {
        return PriceRuleItemFactory::new();
    }

    /**
     * PriceRuleItem.company_id is anchored to its parent PriceRule's
     * company_id, not merely defaulted when missing (D4, aureuserp#137
     * review): the previous `??=`-only derivation let an explicit
     * mismatched company_id through untouched. An item's company is never
     * independent of its rule's, so an explicit disagreement is rejected
     * outright rather than silently overwritten or silently accepted.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($priceRuleItem) {
            $priceRuleItem->creator_id ??= Auth::id();

            static::anchorCompanyToPriceRule($priceRuleItem);

            $priceRuleItem->company_id ??= Auth::user()?->default_company_id;
        });

        static::updating(function ($priceRuleItem) {
            if ($priceRuleItem->isDirty('price_rule_id')) {
                static::anchorCompanyToPriceRule($priceRuleItem);
            }
        });
    }

    private static function anchorCompanyToPriceRule(self $priceRuleItem): void
    {
        if (! $priceRuleItem->price_rule_id) {
            return;
        }

        $priceRule = PriceRule::withoutGlobalScope(CompanyScope::class)->find($priceRuleItem->price_rule_id);

        if (! $priceRule) {
            return;
        }

        if ($priceRuleItem->company_id === null) {
            $priceRuleItem->company_id = $priceRule->company_id;

            return;
        }

        if ((int) $priceRuleItem->company_id !== (int) $priceRule->company_id) {
            throw new AuthorizationException('A price rule item must belong to the same company as its parent price rule.');
        }
    }
}
