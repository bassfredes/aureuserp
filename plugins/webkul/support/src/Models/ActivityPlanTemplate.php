<?php

namespace Webkul\Support\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Security\Models\User;
use Webkul\Support\Database\Factories\ActivityPlanTemplateFactory;
use Webkul\Support\Models\Scopes\CompanyScope;

class ActivityPlanTemplate extends Model implements Sortable
{
    use HasFactory, SortableTrait;

    protected $table = 'activity_plan_templates';

    protected $fillable = [
        'sort',
        'plan_id',
        'activity_type_id',
        'responsible_id',
        'creator_id',
        'delay_count',
        'delay_unit',
        'delay_from',
        'summary',
        'responsible_type',
        'note',
    ];

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    public function activityPlan(): BelongsTo
    {
        return $this->belongsTo(ActivityPlan::class, 'plan_id');
    }

    public function activityType(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class, 'activity_type_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * ActivityPlanTemplate carries no company_id of its own — plan_id is a
     * mandatory FK (migration: cascade-delete), so read isolation is
     * entirely derived by requiring a visible ActivityPlan, the same shape
     * as Milestone::companyViaProject (#138 PR4 ola4B). A Template on a
     * shared (company_id-null) ActivityPlan is visible to everyone the
     * parent already is, with no extra column to filter on.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('companyViaActivityPlan', function (Builder $builder): void {
            $builder->whereHas('activityPlan');
        });
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($activityPlanTemplate) {
            $activityPlanTemplate->creator_id ??= Auth::id();
        });

        // No company_id to persist — exists purely for its authorization
        // side effects, mirroring Milestone::saving(): the persisted
        // ActivityPlan (never a dirty/in-memory relation) must exist, and
        // the acting user must be write-authorized for its company. A
        // shared parent plan restricts template writes to super_admin/
        // system context, matching ActivityPlan's own
        // guardSharedRowMutation() for the parent itself.
        static::saving(function (self $template): void {
            if ($template->plan_id === null) {
                throw new AuthorizationException('An ActivityPlan is required to resolve this template\'s authorization.');
            }

            $plan = ActivityPlan::withoutGlobalScope(CompanyScope::class)->find($template->plan_id);

            if (! $plan) {
                throw new AuthorizationException('The ActivityPlan could not be found.');
            }

            if ($plan->company_id !== null) {
                CompanyScope::assertCanWriteCompany((int) $plan->company_id);

                return;
            }

            if (Auth::check() && ! ActivityPlan::actingUserIsSuperAdmin()) {
                throw new AuthorizationException('Templates on a shared ActivityPlan (company_id is null) can only be managed by a super_admin or a system process.');
            }
        });
    }

    protected static function newFactory(): ActivityPlanTemplateFactory
    {
        return ActivityPlanTemplateFactory::new();
    }
}
