<?php

namespace Webkul\Sale\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Webkul\Sale\Database\Factories\TeamMemberFactory;
use Webkul\Sale\Models\Scopes\ParentDerivedCompanyScope;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

/**
 * Pivot (not a plain Model) so Team::members()'s ->using() wiring makes
 * attach() go through this class's own save() instead of a raw
 * query-builder insert that would bypass every guard below (#138 A4G).
 * No company_id of its own: isolation and authorization both derive from
 * the parent Team. user_id is additionally validated by membership,
 * since a User has no single company_id of its own.
 *
 * The bulk paths Eloquent still routes around this class entirely
 * (detach() and updateExistingPivot() issue raw pivot-table statements
 * and never instantiate the pivot model, regardless of ->using()) are
 * closed one level up, in Webkul\Sale\Models\Relations\TeamMembership.
 */
class TeamMember extends Pivot
{
    use HasFactory, ValidatesRelatedCompanyScope;

    protected $table = 'sales_team_members';

    public $timestamps = false;

    protected $fillable = [
        'team_id',
        'user_id',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Both keys are mandatory even though neither is the pivot's own
     * identity: a membership with no user is not a weaker membership, it
     * is an unvalidatable one, and a null would slip past the membership
     * check below as a silent no-op. A nonexistent user is rejected for
     * the same reason (#138 A4F review 4827999112).
     *
     * Returns the Team's authoritative company so callers that already
     * need it do not resolve the parent twice.
     */
    public static function assertMembershipIsAllowed(?int $teamId, ?int $userId): int
    {
        $companyId = static::resolveEffectiveCompanyIdOrFail($teamId, Team::class, null, 'Team');

        if ($userId === null) {
            throw new AuthorizationException('A TeamMember requires a user.');
        }

        $user = User::find($userId);

        if (! $user) {
            throw new AuthorizationException('The related User does not exist.');
        }

        if (! CompanyScope::allowedCompanyIds($user)->contains($companyId)) {
            throw new AuthorizationException('The related User has no membership in this company.');
        }

        return $companyId;
    }

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope(new ParentDerivedCompanyScope('team'));

        static::creating(function (self $pivot) {
            static::assertMembershipIsAllowed($pivot->team_id, $pivot->user_id);
        });

        // Both keys are fillable and creating()/deleting() alone never
        // re-check a retarget of an already-persisted row (#138 PR4
        // review 4818602853, finding 2). Only detach plus attach may
        // change either.
        static::updating(function (self $pivot) {
            if ($pivot->isDirty(['team_id', 'user_id'])) {
                throw new AuthorizationException('Retargeting a TeamMember is forbidden — detach and attach instead.');
            }
        });

        static::deleting(function (self $pivot) {
            static::resolveEffectiveCompanyIdOrFail($pivot->team_id, Team::class, null, 'Team');
        });
    }

    protected static function newFactory(): TeamMemberFactory
    {
        return TeamMemberFactory::new();
    }
}
