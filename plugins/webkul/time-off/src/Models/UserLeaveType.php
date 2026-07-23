<?php

namespace Webkul\TimeOff\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;
use Webkul\TimeOff\Database\Factories\UserLeaveTypeFactory;

/**
 * Bare pivot (no id/timestamps) between User and LeaveType — no company_id
 * of its own. Its tenant boundary is the LeaveType being notified about,
 * not the User (a notified officer may legitimately belong to several
 * companies); read isolation is parent-scoped via leaveType, the same
 * shape as Milestone/ActivityPlanTemplate. Write authorization additionally
 * requires the target user to actually have access to the LeaveType's
 * company — notifying an officer with no standing in that company at all
 * would be a silent no-op in practice, not a meaningful notification
 * (#138 PR4 ola4B).
 */
class UserLeaveType extends Model
{
    use HasFactory, ValidatesRelatedCompanyScope;

    protected $table = 'time_off_user_leave_types';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'leave_type_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    protected static function booted(): void
    {
        static::addGlobalScope('companyViaLeaveType', function (Builder $builder): void {
            $builder->whereHas('leaveType');
        });
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function (self $userLeaveType): void {
            $companyId = static::resolveEffectiveCompanyIdOrFail($userLeaveType->leave_type_id, LeaveType::class, null, 'LeaveType');

            $user = User::withoutGlobalScope(CompanyScope::class)->find($userLeaveType->user_id);

            if ($user === null) {
                throw new AuthorizationException('The notified User could not be found.');
            }

            $userCompanyIds = $user->allowedCompanies()->pluck('companies.id')->push($user->default_company_id)->filter();

            if (! $userCompanyIds->contains($companyId)) {
                throw new AuthorizationException('The notified User has no access to the LeaveType\'s company.');
            }
        });
    }

    protected static function newFactory(): UserLeaveTypeFactory
    {
        return UserLeaveTypeFactory::new();
    }
}
