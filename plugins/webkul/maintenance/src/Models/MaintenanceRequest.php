<?php

namespace Webkul\Maintenance\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Webkul\Chatter\Traits\HasChatter;
use Webkul\Chatter\Traits\HasLogActivity;
use Webkul\Maintenance\Database\Factories\MaintenanceRequestFactory;
use Webkul\Maintenance\Enums\MaintenanceRepeatType;
use Webkul\Maintenance\Enums\MaintenanceRepeatUnit;
use Webkul\Maintenance\Enums\MaintenanceRequestType;
use Webkul\Security\Models\User;
use Webkul\Security\Traits\HasPermissionScope;
use Webkul\Support\Models\ActivityType;
use Webkul\Support\Models\Company;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\HasStrictCompanyId;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

class MaintenanceRequest extends Model
{
    use HasChatter, HasCompanyScope, HasFactory, HasLogActivity, HasPermissionScope, HasStrictCompanyId, SoftDeletes, ValidatesRelatedCompanyScope;

    public const ACTIVITY_PLAN_PLUGIN = 'maintenance';

    protected $table = 'maintenance_requests';

    protected $fillable = [
        'repeat_interval',
        'name',
        'priority',
        'maintenance_type',
        'instruction_type',
        'instruction_pdf',
        'instruction_google_slide',
        'repeat_unit',
        'repeat_type',
        'requested_at',
        'closed_at',
        'repeat_until',
        'duration',
        'description',
        'instruction_text',
        'recurring_maintenance',
        'scheduled_at',
        'equipment_id',
        'stage_id',
        'category_id',
        'user_id',
        'maintenance_team_id',
        'company_id',
        'creator_id',
        'deleted_at',
    ];

    protected $casts = [
        'repeat_interval'       => 'integer',
        'requested_at'          => 'date',
        'closed_at'             => 'date',
        'repeat_until'          => 'date',
        'duration'              => 'float',
        'recurring_maintenance' => 'boolean',
        'scheduled_at'          => 'datetime',
        'maintenance_type'      => MaintenanceRequestType::class,
        'repeat_unit'           => MaintenanceRepeatUnit::class,
        'repeat_type'           => MaintenanceRepeatType::class,
    ];

    public string $recordTitleAttribute = 'name';

    public function getModelTitle(): string
    {
        return __('maintenance::models/maintenance-request.title');
    }

    protected function getLogAttributeLabels(): array
    {
        return [
            'requested_at' => __('maintenance::models/maintenance-request.log-attributes.requested-at'),
            'user.name'    => __('maintenance::models/maintenance-request.log-attributes.responsible'),
            'stage.name'   => __('maintenance::models/maintenance-request.log-attributes.stage'),
        ];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipment_id')->withTrashed();
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'stage_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EquipmentCategory::class, 'category_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'maintenance_team_id')->withTrashed();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    protected static function newFactory(): MaintenanceRequestFactory
    {
        return MaintenanceRequestFactory::new();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $request): void {
            $request->stage_id ??= Stage::query()->orderBy('sort')->value('id');

            $request->creator_id ??= Auth::id();
        });

        // Runs after HasStrictCompanyId's own `saving` listener has already
        // resolved/authorized $request->company_id (including on the
        // recurring-maintenance replica below, which is a plain new model
        // going through the same create path) — equipment_id, category_id
        // and maintenance_team_id are each independently selectable with no
        // server-side company check today, so a submitted or replicated
        // combination could otherwise anchor a MaintenanceRequest to one
        // company while referencing another company's Equipment/Team/
        // EquipmentCategory (#138 PR4 ola4B).
        static::saving(function (self $request): void {
            static::assertRelatedBelongsToCompany($request->equipment_id, Equipment::class, 'Equipment', $request->company_id);
            static::assertRelatedBelongsToCompany($request->maintenance_team_id, Team::class, 'Team', $request->company_id);
            static::assertRelatedBelongsToCompany($request->category_id, EquipmentCategory::class, 'EquipmentCategory', $request->company_id);
        });

        static::updated(function (self $request): void {
            if ($request->wasChanged('stage_id') && $request->stage()->where('done', true)->exists()) {
                if (
                    $request->maintenance_type !== MaintenanceRequestType::PREVENTIVE
                    || ! $request->recurring_maintenance
                ) {
                    return;
                }

                $scheduledAt = Carbon::parse($request->scheduled_at ?? now());

                $scheduledAt->add($request->repeat_interval, $request->repeat_unit->value.'s');

                if (
                    $request->repeat_type === MaintenanceRepeatType::FOREVER
                    || $scheduledAt->toDateString() <= Carbon::parse($request->repeat_until)->toDateString()
                ) {
                    $stageId = Stage::query()->orderBy('sort')->value('id');

                    if (! $stageId) {
                        return;
                    }

                    $nextRequest = $request->replicate()->fill([
                        'scheduled_at'  => $scheduledAt,
                        'closed_at'     => null,
                        'stage_id'      => $stageId,
                    ]);

                    $nextRequest->save();

                    $activityTypeId = ActivityType::query()
                        ->where('plugin', self::ACTIVITY_PLAN_PLUGIN)
                        ->where('is_active', true)
                        ->orderBy('sort')
                        ->value('id');

                    $nextRequest->addActivity([
                        'activity_type_id' => $activityTypeId,
                        'assigned_to'      => $nextRequest->user_id,
                        'date_deadline'    => Carbon::parse($nextRequest->scheduled_at ?? now()),
                        'summary'          => $nextRequest->name,
                    ]);
                }
            }
        });
    }
}
