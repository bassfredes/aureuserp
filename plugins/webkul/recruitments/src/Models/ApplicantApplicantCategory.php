<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Webkul\Recruitment\Models\Scopes\ParentDerivedCompanyScope;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

/**
 * Pivot (not a plain Model) so Applicant::categories()'s ->using()
 * wiring makes attach()/detach() go through this class's own save()/
 * delete(), not a raw query-builder insert/delete that would bypass
 * every guard below entirely (#138 PR4 A4E). No company_id of its own —
 * isolation and authorization both derive from the parent Applicant.
 *
 * The previous $fillable/factory used 'applicant_category_id', a column
 * that has never existed on this table (the real column is
 * 'category_id', per the 2025_01_13_075926 migration) — dormant since
 * this class had no real write path before this wave activated it.
 */
class ApplicantApplicantCategory extends Pivot
{
    use ValidatesRelatedCompanyScope;

    protected $table = 'recruitments_applicant_applicant_categories';

    protected $fillable = ['applicant_id', 'category_id'];

    public $timestamps = false;

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope(new ParentDerivedCompanyScope('applicant'));

        static::creating(function (self $pivot) {
            static::resolveEffectiveCompanyIdOrFail($pivot->applicant_id, Applicant::class, null, 'Applicant');
        });

        static::deleting(function (self $pivot) {
            static::resolveEffectiveCompanyIdOrFail($pivot->applicant_id, Applicant::class, null, 'Applicant');
        });
    }
}
