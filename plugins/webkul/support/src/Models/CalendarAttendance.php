<?php

namespace Webkul\Support\Models;

use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Security\Models\User;
use Webkul\Support\Database\Factories\CalendarAttendanceFactory;
use Webkul\Support\Enums\CalendarDisplayType;
use Webkul\Support\Enums\CompanyContextMode;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Models\Scopes\ParentDerivedCompanyOrSharedScope;
use Webkul\Support\Services\CompanyContext;

/**
 * parent_scoped on the Calendar side (#138 A4I): no company_id column of
 * its own, isolation and write authorization both derive from the parent
 * Calendar — which may itself be company_or_shared
 * (ParentDerivedCompanyOrSharedScope, not the plain ParentDerivedCompanyScope
 * used elsewhere in this rollout, per orchestrator decision). Mutating an
 * attendance of a shared (company_id IS NULL) Calendar is restricted to a
 * super_admin or an explicit ALL_COMPANIES/BOOTSTRAP system context — same
 * strict precedent as CurrencyRate/Calendar, not ActivityPlan's broader
 * unauthenticated-is-unrestricted tolerance.
 */
class CalendarAttendance extends Model implements Sortable
{
    use HasFactory, SortableTrait;

    protected $table = 'calendar_attendances';

    protected $fillable = [
        'sort',
        'name',
        'day_of_week',
        'day_period',
        'week_type',
        'display_type',
        'date_from',
        'date_to',
        'hour_from',
        'hour_to',
        'duration_days',
        'calendar_id',
        'creator_id',
        'resource_type',
        'resource_id',
    ];

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    public function calendar()
    {
        return $this->belongsTo(Calendar::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function resource(): MorphTo
    {
        return $this->morphTo();
    }

    public static function getWeekType(\DateTime|Carbon $date): int
    {
        return (int) floor(($date->toDateTime()->format('z') + 1) / 7) % 2;
    }

    /**
     * Scopes the sort_when_creating max() lookup to the owning calendar
     * (#138 A4I) — the package default (static::query(), no filter) would
     * compute the next sort value across every calendar's attendances.
     */
    public function buildSortQuery(): Builder
    {
        return static::query()->where('calendar_id', $this->calendar_id);
    }

    /**
     * Duplicated from HasCompanyScope::actingUserIsSuperAdmin() rather than
     * using that trait directly: this model has no company_id column, and
     * HasCompanyScope::bootHasCompanyScope() would register a CompanyScope
     * global scope that filters on a column that does not exist here.
     */
    private static function actingUserIsSuperAdmin(): bool
    {
        $user = Auth::user();

        return (bool) $user?->roles->pluck('name')
            ->contains(fn ($name) => strtolower($name) === 'super_admin');
    }

    private static function assertResourceFieldsAreNull(self $calendarAttendance): void
    {
        if ($calendarAttendance->resource_type !== null || $calendarAttendance->resource_id !== null) {
            throw new AuthorizationException('CalendarAttendance.resource_type/resource_id must remain null — no consumer writes a value into them.');
        }
    }

    private static function assertDisplayTypeIsValid(?string $displayType): void
    {
        if ($displayType === null) {
            return;
        }

        $allowed = array_map(fn (CalendarDisplayType $case) => $case->value, CalendarDisplayType::cases());

        if (! in_array($displayType, $allowed, true)) {
            throw new AuthorizationException("Invalid CalendarAttendance display_type '{$displayType}'.");
        }
    }

    /**
     * Resolves and re-authorizes the parent Calendar: a company-owned
     * parent must be write-authorized for the acting actor via
     * CompanyScope::assertCanWriteCompany(); a shared (company_id IS NULL)
     * parent requires super_admin or an explicit ALL_COMPANIES/BOOTSTRAP
     * system context (#138 A4I). $allowTrashedParent distinguishes a new
     * child (create must reject an archived Calendar) from an existing
     * child's own persisted parent (update/delete must keep reauthorizing
     * it even if the Calendar was archived afterward — #138 A4I review
     * round 2). Calendar's SoftDeletes default scope already excludes a
     * trashed row when withTrashed() is not applied, so create naturally
     * hits the "could not be found" branch instead of needing a separate
     * trashed() check.
     */
    private static function assertParentIsWritable(?int $calendarId, bool $allowTrashedParent): void
    {
        if ($calendarId === null) {
            throw new AuthorizationException('A CalendarAttendance requires a Calendar.');
        }

        $query = Calendar::withoutGlobalScope(CompanyScope::class);

        if ($allowTrashedParent) {
            $query->withTrashed();
        }

        $calendar = $query->find($calendarId);

        if (! $calendar) {
            throw new AuthorizationException('The related Calendar could not be found.');
        }

        if ($calendar->company_id === null) {
            if (static::actingUserIsSuperAdmin()) {
                return;
            }

            if (! Auth::check()) {
                $context = CompanyContext::current();

                if ($context?->mode === CompanyContextMode::ALL_COMPANIES || $context?->mode === CompanyContextMode::BOOTSTRAP) {
                    return;
                }
            }

            throw new AuthorizationException('Attendances of a shared Calendar (company_id is null) can only be created or modified by a super_admin or an explicit system process.');
        }

        CompanyScope::assertCanWriteCompany((int) $calendar->company_id);
    }

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope(new ParentDerivedCompanyOrSharedScope('calendar'));

        static::creating(function (self $calendarAttendance) {
            $calendarAttendance->creator_id ??= Auth::id();

            static::assertResourceFieldsAreNull($calendarAttendance);
            static::assertDisplayTypeIsValid($calendarAttendance->display_type);
            static::assertParentIsWritable($calendarAttendance->calendar_id, allowTrashedParent: false);
        });

        // calendar_id is fillable and creating()/deleting() alone never
        // re-check a retarget of an already-persisted row — only delete and
        // create anew may change it (#138 A4I, same guarantee TeamMember
        // provides for team_id/user_id).
        static::updating(function (self $calendarAttendance) {
            if ($calendarAttendance->isDirty('calendar_id')) {
                throw new AuthorizationException('Retargeting a CalendarAttendance is forbidden — delete and create a new one instead.');
            }

            static::assertResourceFieldsAreNull($calendarAttendance);
            static::assertDisplayTypeIsValid($calendarAttendance->display_type);
            static::assertParentIsWritable($calendarAttendance->getOriginal('calendar_id'), allowTrashedParent: true);
        });

        static::deleting(function (self $calendarAttendance) {
            static::assertParentIsWritable($calendarAttendance->getOriginal('calendar_id'), allowTrashedParent: true);
        });
    }

    protected static function newFactory(): CalendarAttendanceFactory
    {
        return CalendarAttendanceFactory::new();
    }
}
