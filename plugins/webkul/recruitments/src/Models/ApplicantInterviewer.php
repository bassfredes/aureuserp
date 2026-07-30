<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Webkul\Recruitment\Models\Scopes\ParentDerivedCompanyScope;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

/**
 * Pivot (not a plain Model) so Applicant::interviewer()'s ->using()
 * wiring makes attach()/detach() go through this class's own save()/
 * delete(), not a raw query-builder insert/delete that would bypass
 * every guard below entirely (#138 PR4 A4E). No company_id of its own —
 * isolation and authorization both derive from the parent Applicant;
 * interviewer_id is additionally validated by membership, since User has
 * no single company_id of its own.
 */
class ApplicantInterviewer extends Pivot
{
    use ValidatesRelatedCompanyScope;

    protected $table = 'recruitments_applicant_interviewers';

    public $timestamps = false;

    protected $fillable = [
        'applicant_id',
        'interviewer_id',
    ];

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    private static function assertUserBelongsToCompany(?int $userId, ?int $companyId, string $label): void
    {
        if ($userId === null) {
            return;
        }

        $user = User::find($userId);

        if (! $user) {
            return;
        }

        if ($companyId === null || ! CompanyScope::allowedCompanyIds($user)->contains((int) $companyId)) {
            throw new AuthorizationException("The related {$label} has no membership in this company.");
        }
    }

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope(new ParentDerivedCompanyScope('applicant'));

        static::creating(function (self $pivot) {
            $companyId = static::resolveEffectiveCompanyIdOrFail($pivot->applicant_id, Applicant::class, null, 'Applicant');

            static::assertUserBelongsToCompany($pivot->interviewer_id, $companyId, 'Interviewer');
        });

        static::deleting(function (self $pivot) {
            static::resolveEffectiveCompanyIdOrFail($pivot->applicant_id, Applicant::class, null, 'Applicant');
        });
    }
}
