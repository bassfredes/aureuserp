<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Auth\Access\AuthorizationException;
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

        // This pivot's only columns are its two keys — creating()/
        // deleting() authorize attach()/detach(), but neither guards a
        // retarget of an already-persisted row (updateExistingPivot(),
        // or a direct ->update() on a fetched instance), which could move
        // the row to an unauthorized Applicant/category without ever
        // re-checking company (#138 PR4 review 4818602853, finding 2).
        // The only legitimate way to change either key is detach+attach,
        // both of which are already guarded above.
        static::updating(function (self $pivot) {
            if ($pivot->isDirty(['applicant_id', 'category_id'])) {
                throw new AuthorizationException('Retargeting an ApplicantApplicantCategory is forbidden — detach and attach instead.');
            }
        });

        static::deleting(function (self $pivot) {
            static::resolveEffectiveCompanyIdOrFail($pivot->applicant_id, Applicant::class, null, 'Applicant');
        });
    }
}
