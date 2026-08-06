<?php

namespace Webkul\Support\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Security\Models\User;
use Webkul\Support\Database\Factories\UtmCampaignFactory;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\HasStrictCompanyId;

/**
 * strict_company, no shared rows: a marketing campaign always belongs to
 * the company running it. stage_id points at UtmStage, a global catalog
 * with no company_id, so no cross-company stage check is needed (#138 PR4
 * A4J).
 */
class UtmCampaign extends Model
{
    use HasCompanyScope, HasFactory, HasStrictCompanyId;

    protected $table = 'utm_campaigns';

    protected $fillable = [
        'user_id',
        'stage_id',
        'color',
        'creator_id',
        'name',
        'title',
        'is_active',
        'is_auto_campaign',
        'company_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function stage()
    {
        return $this->belongsTo(UtmStage::class, 'stage_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($utmCampaign) {
            $utmCampaign->creator_id ??= Auth::id();
        });

        // HasStrictCompanyId only guards saving (create/update); delete
        // needs its own re-authorization of the persisted company, same
        // as OrderTemplate (#138 PR4 A4H) — HasCompanyScope's read filter
        // alone does not cover a row loaded via forAllCompanies() or a
        // system context bypass.
        static::deleting(function (self $utmCampaign) {
            CompanyScope::assertCanWriteCompany((int) $utmCampaign->company_id);
        });
    }

    protected static function newFactory()
    {
        return UtmCampaignFactory::new();
    }
}
