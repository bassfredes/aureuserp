<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Models\Skill;
use Webkul\Employee\Models\SkillLevel;
use Webkul\Employee\Models\SkillType;
use Webkul\Recruitment\Models\Scopes\ParentDerivedCompanyScope;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

/**
 * No company_id of its own (#138 PR4 A4E) — isolation derives entirely
 * from the Candidate it belongs to, via ParentDerivedCompanyScope
 * (mirrors the EmployeeSkillCompanyScope precedent from the employees
 * family). Writes reauthorize against the persisted Candidate,
 * re-querying its candidate_id fresh by primary key rather than trusting
 * getOriginal() or the in-memory attribute — a row fetched via a partial
 * column projection never populates candidate_id at all, and an
 * unresolvable parent must reject the mutation, not silently let it
 * through (#138 PR4 A4D review 4811425870/4811942781, applied here from
 * the start rather than in a later correction). user_id is additionally
 * validated by membership, since User has no single company_id of its
 * own.
 */
class CandidateSkill extends Model
{
    use ValidatesRelatedCompanyScope;

    protected $table = 'recruitments_candidate_skills';

    protected $fillable = [
        'candidate_id',
        'skill_id',
        'skill_level_id',
        'skill_type_id',
        'creator_id',
        'user_id',
    ];

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }

    public function skillLevel()
    {
        return $this->belongsTo(SkillLevel::class);
    }

    public function skillType()
    {
        return $this->belongsTo(SkillType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
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

    private static function authorizeAgainstPersistedCandidate(?int $candidateId): int
    {
        return static::resolveEffectiveCompanyIdOrFail($candidateId, Candidate::class, null, 'Candidate');
    }

    /**
     * Re-queries candidate_id fresh by primary key, bypassing this model's
     * own scope — never getOriginal() and never the in-memory attribute.
     * Same rationale as EmployeeSkill::resolvePersistedEmployeeId() (#138
     * PR4 A4D review 4811425870, finding 2): a row fetched via a partial
     * projection never populates candidate_id at all.
     */
    private static function resolvePersistedCandidateId(self $candidateSkill): ?int
    {
        if (! $candidateSkill->exists) {
            return null;
        }

        return static::withoutGlobalScope(ParentDerivedCompanyScope::class)
            ->whereKey($candidateSkill->getKey())
            ->value('candidate_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope(new ParentDerivedCompanyScope('candidate'));

        static::creating(function (self $candidateSkill) {
            $candidateSkill->creator_id ??= Auth::id();

            $companyId = static::authorizeAgainstPersistedCandidate($candidateSkill->candidate_id);

            static::assertUserBelongsToCompany($candidateSkill->user_id, $companyId, 'User');
        });

        static::updating(function (self $candidateSkill) {
            $originalCandidateId = static::resolvePersistedCandidateId($candidateSkill);
            $originalCompanyId = static::authorizeAgainstPersistedCandidate($originalCandidateId);

            if ($candidateSkill->isDirty('candidate_id')) {
                $newCompanyId = static::authorizeAgainstPersistedCandidate($candidateSkill->candidate_id);

                if ($originalCompanyId !== $newCompanyId) {
                    throw new AuthorizationException('Moving a CandidateSkill to a Candidate in a different company is forbidden.');
                }
            }

            if ($candidateSkill->isDirty('user_id')) {
                static::assertUserBelongsToCompany($candidateSkill->user_id, $originalCompanyId, 'User');
            }
        });

        static::deleting(function (self $candidateSkill) {
            static::authorizeAgainstPersistedCandidate(static::resolvePersistedCandidateId($candidateSkill));
        });
    }
}
