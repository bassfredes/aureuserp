<?php

namespace Webkul\Sale\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Sale\Database\Factories\TagFactory;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\HasStrictCompanyId;

/**
 * strict_company, no shared rows (#138 PR4 A4K, 2026-08-03 adversarial
 * design review): no company_or_shared contract and no Calendar-style
 * default seeder exists for tags, so a Tag used by orders from more than
 * one company is a genuine data conflict rather than something to expose as
 * shared — see Console\Commands\BackfillTagCompanyId for the historical
 * backfill. Same HasCompanyScope + HasStrictCompanyId contract as
 * Journal/PaymentTerm/PaymentToken (#138 PR4 A4J).
 *
 * A legacy orphan tag (no associated order, left with company_id null by
 * the backfill command) is invisible under strict_company read isolation
 * and every write (including delete) fails closed via
 * CompanyScope::assertCanWriteCompany(0) until a human assigns it a real
 * company_id — intentional, not a bug.
 */
class Tag extends Model
{
    use HasCompanyScope, HasFactory, HasStrictCompanyId;

    protected $table = 'sales_tags';

    protected $fillable = [
        'color',
        'name',
        'creator_id',
        'company_id',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($tag) {
            $tag->creator_id ??= Auth::id();
        });

        // HasStrictCompanyId only guards saving (create/update); delete
        // needs its own re-authorization of the persisted company, same as
        // PaymentToken/OrderTemplate (#138 PR4 A4H/A4J).
        static::deleting(function (self $tag) {
            CompanyScope::assertCanWriteCompany((int) $tag->company_id);
        });
    }

    protected static function newFactory(): TagFactory
    {
        return TagFactory::new();
    }
}
