<?php

namespace Webkul\Chatter\Models;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Webkul\Chatter\Concerns\ResolvesChatterCompany;
use Webkul\Chatter\Services\ChatterNotificationService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ActivityType;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Contracts\IncludesSharedCompanyRows;
use Webkul\Support\Traits\HasCompanyScope;

/**
 * company_id is always derived from `messageable` (see
 * ResolvesChatterCompany::applyChatterOwnerCompany()), never from the
 * acting causer — a caller passing an explicit company_id is only ever
 * used as a cross-check, rejected on mismatch, never trusted as the source
 * of truth (#138 PR4 chatter gap, 2026-08-03 Codex adversarial review).
 * IncludesSharedCompanyRows: a null company_id here means either a
 * legitimate company_or_shared owner, or a pre-existing row from before
 * this migration that has not been backfilled yet (see
 * Console\Commands\BackfillChatterCompanyId) — visible everywhere rather
 * than becoming invisible to everyone until backfilled, matching the
 * ActivityPlan/Route precedent for company_or_shared rollouts.
 */
class Message extends Model implements IncludesSharedCompanyRows
{
    use HasCompanyScope, ResolvesChatterCompany;

    protected $table = 'chatter_messages';

    protected $fillable = [
        'company_id',
        'activity_type_id',
        'messageable_type',
        'messageable_id',
        'type',
        'name',
        'subject',
        'body',
        'summary',
        'is_internal',
        'date_deadline',
        'pinned_at',
        'log_name',
        'event',
        'assigned_to',
        'causer_type',
        'causer_id',
        'properties',
    ];

    protected $casts = [
        'properties'    => 'array',
        'date_deadline' => 'date',
    ];

    public function messageable(): MorphTo
    {
        return $this->morphTo();
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function activityType()
    {
        return $this->belongsTo(ActivityType::class, 'activity_type_id');
    }

    public function causer()
    {
        return $this->morphTo();
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function setPropertiesAttribute($value)
    {
        $this->attributes['properties'] = json_encode($value);
    }

    public static function boot()
    {
        parent::boot();

        static::saving(function (self $message) {
            static::applyChatterOwnerCompany($message, 'messageable');
        });

        $user = Filament::auth()->user() ?? Auth::user();

        if ($user) {
            static::creating(function ($data) use ($user) {
                // Do not overwrite a causer already set explicitly (e.g. a
                // Partner acting as the causer of an unauthenticated
                // capability response, D5b/#138) — only fill it in when
                // the caller left it blank.
                if ($data->causer_type && $data->causer_id) {
                    return;
                }

                DB::transaction(function () use ($data, $user) {
                    $data->causer_type = $user->getMorphClass();
                    $data->causer_id = $user->id;
                });
            });

            static::updating(function ($data) use ($user) {
                if ($data->causer_type && $data->causer_id) {
                    return;
                }

                $data->causer_type = $user->getMorphClass();
                $data->causer_id = $user->id;
            });
        }

        static::created(function (Message $message) {
            app()->terminating(function () use ($message) {
                $message->unsetRelation('messageable');

                app(ChatterNotificationService::class)->notifyFollowers($message);
            });
        });
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class, 'message_id');
    }
}
