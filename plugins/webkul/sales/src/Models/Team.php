<?php

namespace Webkul\Sale\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Chatter\Traits\HasChatter;
use Webkul\Chatter\Traits\HasLogActivity;
use Webkul\Sale\Database\Factories\TeamFactory;
use Webkul\Sale\Models\Concerns\GuardsCompanyLifecycleOnSoftDelete;
use Webkul\Sale\Models\Relations\TeamMembership;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\HasStrictCompanyId;

/**
 * Strict company owner (#138 A4G): a sales Team has a company_id of its
 * own and is referenced by sales_orders.team_id, so HasStrictCompanyId
 * governs create/update authorization and immutability here, while
 * GuardsCompanyLifecycleOnSoftDelete covers the delete/restore/
 * forceDelete paths that trait does not reach. Its membership pivot
 * (TeamMember) carries no company of its own and derives ownership from
 * this model.
 */
class Team extends Model implements Sortable
{
    use GuardsCompanyLifecycleOnSoftDelete, HasChatter, HasCompanyScope, HasFactory, HasLogActivity, HasStrictCompanyId, SoftDeletes, SortableTrait;

    public const ACTIVITY_PLAN_PLUGIN = 'sales';

    protected $table = 'sales_teams';

    protected $fillable = [
        'sort',
        'company_id',
        'user_id',
        'color',
        'creator_id',
        'name',
        'is_active',
        'invoiced_target',
    ];

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    public function getLogAttributeLabels(): array
    {
        return [
            'name'               => __('sales::models/team.log-attributes.name'),
            'company.name'       => __('sales::models/team.log-attributes.company'),
            'user.name'          => __('sales::models/team.log-attributes.team_leader'),
            'creator.name'       => __('sales::models/team.log-attributes.creator'),
            'is_active'          => __('sales::models/team.log-attributes.status'),
            'invoiced_target'    => __('sales::models/team.log-attributes.invoiced_target'),
        ];
    }

    public function getModelTitle(): string
    {
        return __('sales::models/team.title');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Built by hand rather than through belongsToMany() so only THIS
     * relation gets the guarded TeamMembership subclass — overriding
     * newBelongsToMany() would silently re-route every other
     * belongsToMany reaching this model, including the ones the chatter
     * traits contribute.
     */
    public function members(): BelongsToMany
    {
        $instance = $this->newRelatedInstance(User::class);

        $relation = new TeamMembership(
            $instance->newQuery(),
            $this,
            'sales_team_members',
            'team_id',
            'user_id',
            $this->getKeyName(),
            $instance->getKeyName(),
            'members',
        );

        return $relation->using(TeamMember::class);
    }

    protected static function newFactory(): TeamFactory
    {
        return TeamFactory::new();
    }

    /**
     * A User has no single company_id of its own, so membership is the
     * only meaningful company test for one: default_company_id plus the
     * allowedCompanies() pivot, exactly what CompanyScope::allowedCompanyIds()
     * reads. A nonexistent id is rejected outright rather than silently
     * ignored (#138 A4F review 4827999112 established that a missing
     * related user must fail closed, not no-op).
     */
    private static function assertUserBelongsToCompany(?int $userId, ?int $companyId, string $label): void
    {
        if ($userId === null) {
            return;
        }

        $user = User::find($userId);

        if (! $user) {
            throw new AuthorizationException("The related {$label} does not exist.");
        }

        if ($companyId === null || ! CompanyScope::allowedCompanyIds($user)->contains((int) $companyId)) {
            throw new AuthorizationException("The related {$label} has no membership in this company.");
        }
    }

    protected static function boot()
    {
        parent::boot();

        // Fires after HasStrictCompanyId's own `saving` listener has
        // already resolved and authorized company_id, so it is safe to
        // trust on both create and update.
        //
        // creator_id follows the contract settled in A4F (reviews
        // 4827999112 and 4830829763): defaulted to the acting user on
        // create, validated when explicit, and immutable afterwards via
        // isDirty() — unconditionally, so a row whose creator was removed
        // by the FK's nullOnDelete() cannot later be claimed by anyone.
        //
        // user_id (the team leader) is re-validated on EVERY save, not
        // only when dirty: the company itself is what the membership is
        // relative to, so an unchanged leader still has to be re-checked
        // whenever the row is written.
        static::saving(function (self $team) {
            if (! $team->exists) {
                $team->creator_id ??= Auth::id();

                static::assertUserBelongsToCompany($team->creator_id, $team->company_id, 'Creator');
            } elseif ($team->isDirty('creator_id')) {
                throw new AuthorizationException("Changing this Team's creator is forbidden.");
            }

            static::assertUserBelongsToCompany($team->user_id, $team->company_id, 'Team Leader');
        });
    }
}
