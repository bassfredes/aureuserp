<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Webkul\Recruitment\Models\Scopes\ParentDerivedCompanyScope;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

/**
 * Pivot (not a plain Model) so Candidate::categories()'s ->using()
 * wiring makes attach()/detach() go through this class's own save()/
 * delete(), not a raw query-builder insert/delete that would bypass
 * every guard below entirely (#138 PR4 A4E). No company_id of its own —
 * isolation and authorization both derive from the parent Candidate.
 *
 * The previous $fillable/factory used 'applicant_category_id', a column
 * that has never existed on this table (the real column is
 * 'category_id', per the 2025_01_10_045048 migration) — dormant since
 * this class had no real write path before this wave activated it.
 */
class CandidateApplicantCategory extends Pivot
{
    use ValidatesRelatedCompanyScope;

    protected $table = 'recruitments_candidate_applicant_categories';

    protected $fillable = ['candidate_id', 'category_id'];

    public $timestamps = false;

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope(new ParentDerivedCompanyScope('candidate'));

        static::creating(function (self $pivot) {
            static::resolveEffectiveCompanyIdOrFail($pivot->candidate_id, Candidate::class, null, 'Candidate');
        });

        // See ApplicantApplicantCategory::boot() — same retargeting gap
        // (#138 PR4 review 4818602853, finding 2).
        static::updating(function (self $pivot) {
            if ($pivot->isDirty(['candidate_id', 'category_id'])) {
                throw new AuthorizationException('Retargeting a CandidateApplicantCategory is forbidden — detach and attach instead.');
            }
        });

        static::deleting(function (self $pivot) {
            static::resolveEffectiveCompanyIdOrFail($pivot->candidate_id, Candidate::class, null, 'Candidate');
        });
    }
}
