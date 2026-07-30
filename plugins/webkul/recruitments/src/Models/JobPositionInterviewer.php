<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Webkul\Recruitment\Models\Scopes\ParentDerivedCompanyScope;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

/**
 * Pivot (not a plain Model) so JobPosition::interviewers()'s ->using()
 * wiring makes attach()/detach() go through this class's own save()/
 * delete(), not a raw query-builder insert/delete that would bypass
 * every guard below entirely (#138 PR4 A4E). No company_id of its own —
 * isolation and authorization both derive from the parent JobPosition;
 * user_id is additionally validated by membership, since User has no
 * single company_id of its own.
 */
class JobPositionInterviewer extends Pivot
{
    use ValidatesRelatedCompanyScope;

    protected $table = 'recruitments_job_position_interviewers';

    protected $fillable = ['job_position_id', 'user_id'];

    public $timestamps = false;

    public function jobPosition()
    {
        return $this->belongsTo(JobPosition::class, 'job_position_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
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

        static::addGlobalScope(new ParentDerivedCompanyScope('jobPosition'));

        static::creating(function (self $pivot) {
            $companyId = static::resolveEffectiveCompanyIdOrFail($pivot->job_position_id, JobPosition::class, null, 'Job Position');

            static::assertUserBelongsToCompany($pivot->user_id, $companyId, 'User');
        });

        static::deleting(function (self $pivot) {
            static::resolveEffectiveCompanyIdOrFail($pivot->job_position_id, JobPosition::class, null, 'Job Position');
        });
    }
}
