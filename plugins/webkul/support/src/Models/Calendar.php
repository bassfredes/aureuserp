<?php

namespace Webkul\Support\Models;

use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Webkul\Field\Traits\HasCustomFields;
use Webkul\Security\Models\User;
use Webkul\Support\Database\Factories\CalendarFactory;
use Webkul\Support\Enums\CompanyContextMode;
use Webkul\Support\Models\Contracts\IncludesSharedCompanyRows;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;
use Webkul\Support\Traits\HasCompanyScope;

/**
 * company_id IS NULL rows are system-managed shared references (the
 * CalendarSeeder-installed "Standard 40 hours/week" default, referenced by
 * every fresh install's WorkCenterResource) — same company_or_shared
 * treatment as ActivityPlan/CurrencyRate (ADR 0007). The shared-row
 * mutation guard follows CurrencyRate's stricter precedent rather than
 * ActivityPlan's: a no-authenticated-user caller must be inside an
 * explicit ALL_COMPANIES/BOOTSTRAP CompanyContext to mutate a shared row,
 * not merely unauthenticated (#138 A4I, per orchestrator decision).
 * resource_type/resource_id were removed from $fillable — those columns do
 * not exist on the `calendars` table (only calendar_leaves and
 * calendar_attendances got nullableMorphs).
 */
class Calendar extends Model implements IncludesSharedCompanyRows
{
    use HasCompanyScope, HasCustomFields, HasFactory, SoftDeletes;

    /** Sentinel for resolveAnonymousLeaveBucketCompanyId(): no company restriction (ALL_COMPANIES/BOOTSTRAP + anyCalendar only). */
    private const LEAVE_BUCKET_UNRESTRICTED = -1;

    /** Sentinel for resolveAnonymousLeaveBucketCompanyId(): no single effective company could be determined — bucket stays empty. */
    private const LEAVE_BUCKET_EMPTY = 0;

    protected $table = 'calendars';

    protected $fillable = [
        'name',
        'timezone',
        'hours_per_day',
        'is_active',
        'two_weeks_calendar',
        'flexible_hours',
        'full_time_required_hours',
        'creator_id',
        'company_id',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function attendance()
    {
        return $this->hasMany(CalendarAttendance::class);
    }

    public function calendarLeaves(): HasMany
    {
        return $this->hasMany(CalendarLeave::class);
    }

    /**
     * Mirrors CurrencyRate::guardSharedRowMutation() rather than
     * ActivityPlan's looser variant: a no-user caller must also be inside
     * an explicit ALL_COMPANIES/BOOTSTRAP system context to mutate a
     * shared row here — an absent context (no user, no CompanyContext at
     * all) is rejected rather than treated as an unrestricted system
     * process (#138 A4I, explicit orchestrator decision).
     */
    protected static function guardSharedRowMutation(bool $isNullCompany): void
    {
        if (! $isNullCompany) {
            return;
        }

        if (static::actingUserIsSuperAdmin()) {
            return;
        }

        if (! Auth::check()) {
            $context = CompanyContext::current();

            if ($context?->mode === CompanyContextMode::ALL_COMPANIES || $context?->mode === CompanyContextMode::BOOTSTRAP) {
                return;
            }
        }

        throw new AuthorizationException('Shared Calendar records (company_id is null) can only be created or modified by a super_admin or an explicit system process.');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $calendar) {
            $authUser = Auth::user();

            $calendar->creator_id ??= $authUser?->id;
            $calendar->company_id ??= $authUser?->default_company_id;

            static::guardSharedRowMutation($calendar->company_id === null);

            if ($calendar->company_id !== null) {
                CompanyScope::assertCanWriteCompany((int) $calendar->company_id);
            }
        });

        static::updating(function (self $calendar) {
            $originalCompanyId = $calendar->getOriginal('company_id');

            static::guardSharedRowMutation($originalCompanyId === null);

            if ($calendar->isDirty('company_id')) {
                throw new AuthorizationException('Changing the company of this Calendar is forbidden — archive it and create a new one instead.');
            }

            if ($originalCompanyId !== null) {
                CompanyScope::assertCanWriteCompany((int) $originalCompanyId);
            }
        });

        static::deleting(function (self $calendar) {
            $companyId = $calendar->getOriginal('company_id');

            static::guardSharedRowMutation($companyId === null);

            if ($companyId !== null) {
                CompanyScope::assertCanWriteCompany((int) $companyId);
            }
        });

        static::forceDeleting(function (self $calendar) {
            $companyId = $calendar->getOriginal('company_id');

            static::guardSharedRowMutation($companyId === null);

            if ($companyId !== null) {
                CompanyScope::assertCanWriteCompany((int) $companyId);
            }

            // A force-delete triggers the calendar_leaves FK's ON DELETE
            // SET NULL at the database level, which would silently produce
            // a CalendarLeave with calendar_id = NULL — a state the
            // strict_company CalendarLeave contract forbids creating
            // through the application (#138 A4I). Blocking here keeps
            // that invariant true regardless of delete path. withoutGlobalScope
            // is required because a shared Calendar can have CalendarLeave
            // rows anchored to companies other than the acting actor's own —
            // all of them must count, not just the visible ones.
            if ($calendar->calendarLeaves()->withoutGlobalScope(CompanyScope::class)->exists()) {
                throw new AuthorizationException('Cannot permanently delete a Calendar that is still referenced by CalendarLeave records.');
            }
        });

        static::restoring(function (self $calendar) {
            $companyId = $calendar->company_id;

            static::guardSharedRowMutation($companyId === null);

            if ($companyId !== null) {
                CompanyScope::assertCanWriteCompany((int) $companyId);
            }
        });
    }

    protected static function newFactory(): CalendarFactory
    {
        return CalendarFactory::new();
    }

    public function planHours(float $hours, Carbon $dayDt, bool $computeLeaves = false, ?array $filters = null, $resource = null): Carbon|false
    {
        [$dayDt, $revert] = make_aware($dayDt);

        if ($computeLeaves) {
            $getIntervals = fn ($start, $end) => $this->getWorkIntervalsBatch($start, $end, resources: $resource, filters: $filters);
            $resourceId = $resource?->id;
        } else {
            $getIntervals = fn ($start, $end) => $this->getAttendanceIntervalsBatch($start, $end);
            $resourceId = null;
        }

        $delta = 14;

        if ($hours >= 0) {
            for ($n = 0; $n < 100; $n++) {
                $dt = $dayDt->copy()->addDays($delta * $n);

                foreach ($getIntervals($dt, $dt->copy()->addDays($delta))[$resourceId] as [$start, $stop, $meta]) {
                    $intervalHours = $start->diffInSeconds($stop) / 3600;

                    if ($hours <= $intervalHours) {
                        return $revert($start->copy()->addSeconds($hours * 3600));
                    }

                    $hours -= $intervalHours;
                }
            }

            return false;
        } else {
            $hours = abs($hours);

            for ($n = 0; $n < 100; $n++) {
                $dt = $dayDt->copy()->subDays($delta * $n);

                $intervals = collect($getIntervals($dt->copy()->subDays($delta), $dt)[$resourceId])->reverse()->values();

                foreach ($intervals as [$start, $stop, $meta]) {
                    $intervalHours = $start->diffInSeconds($stop) / 3600;

                    if ($hours <= $intervalHours) {
                        return $revert($stop->copy()->subSeconds($hours * 3600));
                    }

                    $hours -= $intervalHours;
                }
            }

            return false;
        }
    }

    public function getAttendanceIntervalsDaysData(iterable $intervals): array
    {
        $hoursPerDay = [];

        foreach ($intervals as [$start, $stop]) {
            $day = $start->toDateString();

            $hoursPerDay[$day] = ($hoursPerDay[$day] ?? 0.0) + $start->diffInSeconds($stop) / 3600;
        }

        return [
            'days'  => count($hoursPerDay),
            'hours' => array_sum($hoursPerDay),
        ];
    }

    public function getWorkDurationData(
        Carbon $fromDatetime,
        Carbon $toDatetime,
        bool $computeLeaves = true,
        ?array $filters = null
    ): array {
        if ($computeLeaves) {
            $intervals = $this->getWorkIntervalsBatch($fromDatetime, $toDatetime, filters: $filters)[null];
        } else {
            $intervals = $this->getAttendanceIntervalsBatch($fromDatetime, $toDatetime, filters: $filters)[null];
        }

        return $this->getAttendanceIntervalsDaysData($intervals);
    }

    public function getWorkIntervalsBatch(
        Carbon $startDt,
        Carbon $endDt,
        mixed $resources = null,
        ?array $filters = null,
        ?string $timezone = null,
        bool $computeLeaves = true
    ): array {
        if (! $resources) {
            $resourcesList = [null];
        } else {
            $resourcesList = is_array($resources) ? array_merge($resources, [null]) : [$resources, null];
        }

        $attendanceIntervals = $this->getAttendanceIntervalsBatch($startDt, $endDt, $resources, timezone: $timezone);

        if ($computeLeaves) {
            $leaveIntervals = $this->getLeaveIntervalsBatch($startDt, $endDt, $resources, $filters, timezone: $timezone);

            $result = [];

            foreach ($resourcesList as $resource) {
                $resourceId = $resource?->id;

                $result[$resourceId] = $this->subtractIntervals($attendanceIntervals[$resourceId], $leaveIntervals[$resourceId]);
            }

            return $result;
        }

        $result = [];

        foreach ($resourcesList as $resource) {
            $resourceId = $resource?->id;

            $result[$resourceId] = $attendanceIntervals[$resourceId];
        }

        return $result;
    }

    public function getAttendanceIntervalsBatch(
        Carbon $startDt,
        Carbon $endDt,
        mixed $resources = null,
        ?array $filters = null,
        ?string $timezone = null,
        bool $lunch = false
    ): array {
        if (! $resources) {
            $resourcesList = [null];
        } else {
            $resourcesList = is_array($resources) ? array_merge($resources, [null]) : [$resources, null];
        }

        $resourcesByType = collect($resourcesList)
            ->filter()
            ->groupBy(fn ($resource) => $resource->getMorphClass())
            ->map(fn ($group) => $group->pluck('id')->all())
            ->all();

        $attendances = CalendarAttendance::query()
            ->where(fn ($q) => $this->applyFilters($q, $filters ?? []))
            ->where('calendar_id', $this->id)
            ->where(function ($q) use ($resourcesByType) {
                $q->whereNull('resource_id');
                foreach ($resourcesByType as $type => $ids) {
                    $q->orWhere(fn ($q) => $q->where('resource_type', $type)->whereIn('resource_id', $ids));
                }
            })
            ->whereNull('display_type')
            ->where('day_period', $lunch ? '=' : '!=', 'lunch')
            ->get();

        $resourcesPerTz = [];

        foreach ($resourcesList as $resource) {
            $resourceTz = $timezone
                ?? ($resource ? ($resource->timezone ?: null) : null)
                ?? $this->timezone;

            $resourcesPerTz[$resourceTz][] = $resource;
        }

        $attendancePerResource = [];

        $attendancesPerDay = array_map(fn () => [], range(0, 13));

        $weekdays = [];

        $dayOfWeekMap = [
            'monday'    => 0,
            'tuesday'   => 1,
            'wednesday' => 2,
            'thursday'  => 3,
            'friday'    => 4,
            'saturday'  => 5,
            'sunday'    => 6,
        ];

        foreach ($attendances as $attendance) {
            if ($attendance->resource_id) {
                $attendancePerResource[$attendance->resource_id][] = $attendance;
            }

            $weekday = $dayOfWeekMap[$attendance->day_of_week] ?? 0;

            $weekdays[] = $weekday;

            if ($this->two_weeks_calendar) {
                $weekType = (int) $attendance->week_type;

                $attendancesPerDay[$weekday + 7 * $weekType][] = $attendance;
            } else {
                $attendancesPerDay[$weekday][] = $attendance;
                $attendancesPerDay[$weekday + 7][] = $attendance;
            }
        }

        $weekdays = array_unique($weekdays);

        $start = $startDt->clone()->utc();

        $end = $endDt->clone()->utc();

        $boundsPerTz = [];

        foreach ($resourcesPerTz as $resourceTz => $tzResources) {
            $boundsPerTz[$resourceTz] = [
                $startDt->clone()->setTimezone($resourceTz),
                $endDt->clone()->setTimezone($resourceTz),
            ];

            $start = min($start, $boundsPerTz[$resourceTz][0]->clone()->utc());

            $end = max($end, $boundsPerTz[$resourceTz][1]->clone()->utc());
        }

        $baseResult = [];

        $perResourceResult = [];

        $current = $start->clone()->startOfDay();

        while ($current->lte($end)) {
            $systemDay = $current->dayOfWeek === 0 ? 6 : $current->dayOfWeek - 1;

            if (in_array($systemDay, $weekdays)) {
                $weekType = CalendarAttendance::getWeekType($current);

                $dayAttends = $attendancesPerDay[$systemDay + 7 * $weekType];

                foreach ($dayAttends as $attendance) {
                    if (
                        ($attendance->date_from && $current->lt(Carbon::parse($attendance->date_from))) ||
                        ($attendance->date_to && Carbon::parse($attendance->date_to)->lt($current))
                    ) {
                        continue;
                    }

                    $dayFrom = $current->clone()->setTime(0, 0)->addMinutes($attendance->hour_from * 60);

                    $dayTo = $current->clone()->setTime(0, 0)->addMinutes($attendance->hour_to * 60);

                    if ($attendance->resource_id) {
                        $perResourceResult[$attendance->resource_id][] = [$dayFrom, $dayTo, $attendance];
                    } else {
                        $baseResult[] = [$dayFrom, $dayTo, $attendance];
                    }
                }
            }

            $current->addDay();
        }

        $resultPerTz = [];

        foreach ($resourcesPerTz as $resourceTz => $tzResources) {
            $bounds = $boundsPerTz[$resourceTz];

            $resultPerTz[$resourceTz] = collect($baseResult)->map(fn ($val) => [
                Carbon::parse(max($bounds[0], Carbon::parse($val[0])->setTimezone($resourceTz))),
                Carbon::parse(min($bounds[1], Carbon::parse($val[1])->setTimezone($resourceTz))),
                $val[2],
            ])->filter(fn ($interval) => $interval[0]->lt($interval[1]))->values()->all();
        }

        $resultPerResourceId = [];

        foreach ($resourcesPerTz as $resourceTz => $tzResources) {
            $res = $resultPerTz[$resourceTz];

            foreach ($tzResources as $resource) {
                $resourceId = $resource?->id;

                if ($resource && isset($perResourceResult[$resourceId])) {
                    $bounds = $boundsPerTz[$resourceTz];

                    $resourceSpecificResult = collect($perResourceResult[$resourceId])->map(fn ($val) => [
                        Carbon::parse(max($bounds[0], Carbon::parse($val[0])->setTimezone($resourceTz))),
                        Carbon::parse(min($bounds[1], Carbon::parse($val[1])->setTimezone($resourceTz))),
                        $val[2],
                    ])->filter(fn ($interval) => $interval[0]->lt($interval[1]))->values()->all();

                    $resultPerResourceId[$resourceId] = collect(array_merge($res, $resourceSpecificResult));
                } else {
                    $resultPerResourceId[$resourceId] = collect($res);
                }
            }
        }

        return $resultPerResourceId;
    }

    /**
     * The `null`/anonymous bucket of getLeaveIntervalsBatch() represents
     * exactly one effective company, never every company the caller's
     * CompanyScope on CalendarLeave happens to make visible (#138 A4I
     * review round 2 — a multi-company actor was seeing every allowed
     * company's leaves merged into that one bucket). Returns
     * LEAVE_BUCKET_UNRESTRICTED only when anyCalendar=true under an
     * explicit ALL_COMPANIES/BOOTSTRAP system context — anyCalendar never
     * widens company visibility for an authenticated user or a
     * CompanyContext::COMPANY caller, only which calendars are searched.
     * Returns LEAVE_BUCKET_EMPTY when no single effective company can be
     * determined, so the bucket fails closed instead of guessing.
     */
    private function resolveAnonymousLeaveBucketCompanyId(bool $anyCalendar): int
    {
        if ($this->company_id !== null) {
            return (int) $this->company_id;
        }

        $context = CompanyContext::current();

        if ($anyCalendar && ($context?->mode === CompanyContextMode::ALL_COMPANIES || $context?->mode === CompanyContextMode::BOOTSTRAP)) {
            return self::LEAVE_BUCKET_UNRESTRICTED;
        }

        if (Auth::check()) {
            $companyId = Auth::user()?->default_company_id;

            return $companyId !== null ? (int) $companyId : self::LEAVE_BUCKET_EMPTY;
        }

        if ($context?->mode === CompanyContextMode::COMPANY) {
            return (int) $context->companyId;
        }

        return self::LEAVE_BUCKET_EMPTY;
    }

    public function getLeaveIntervalsBatch(
        Carbon $startDt,
        Carbon $endDt,
        mixed $resources = null,
        ?array $filters = null,
        ?string $timezone = null,
        bool $anyCalendar = false
    ): array {
        if (! $resources) {
            $resourcesList = [null];
        } else {
            $resourcesList = is_array($resources) ? array_merge($resources, [null]) : [$resources, null];
        }

        if ($filters === null) {
            $filters = [['time_type', '=', 'leave']];
        }

        $anonymousBucketCompanyId = $this->resolveAnonymousLeaveBucketCompanyId($anyCalendar);

        $resourcesByType = collect($resourcesList)
            ->filter()
            ->groupBy(fn ($resource) => $resource->getMorphClass())
            ->map(fn ($group) => $group->pluck('id')->all())
            ->all();

        $allLeaves = CalendarLeave::query()
            ->where(fn ($q) => $this->applyFilters($q, $filters))
            // A leave never mixes calendars — calendar_id IS NULL is not a
            // cross-calendar/cross-company wildcard, it is not a state
            // CalendarLeave can persist at all under its strict_company
            // contract (#138 A4I, closes the cross-company leak this
            // wildcard produced).
            ->when(! $anyCalendar, fn ($q) => $q->where('calendar_id', $this->id))
            ->where(function ($q) use ($resourcesByType) {
                $q->whereNull('resource_id');
                foreach ($resourcesByType as $type => $ids) {
                    $q->orWhere(fn ($q) => $q->where('resource_type', $type)->whereIn('resource_id', $ids));
                }
            })
            ->where('date_from', '<=', $endDt->toDateTimeString())
            ->where('date_to', '>=', $startDt->toDateTimeString())
            ->get();

        $result = [];

        $tzDates = [];

        foreach ($allLeaves as $leave) {
            $leaveResource = $leave->resource;

            $leaveCompany = $leave->company;

            $leaveDateFrom = $leave->date_from;

            $leaveDateTo = $leave->date_to;

            foreach ($resourcesList as $resource) {
                if (
                    $leaveResource
                    && $leaveResource->id !== ($resource?->id)
                    || (
                        ! $leaveResource
                        && $resource
                        && $resource->company_id !== $leaveCompany?->id
                    )
                    || (
                        ! $leaveResource
                        && ! $resource
                        && $anonymousBucketCompanyId !== self::LEAVE_BUCKET_UNRESTRICTED
                        && (
                            $anonymousBucketCompanyId === self::LEAVE_BUCKET_EMPTY
                            || (int) $leaveCompany?->id !== $anonymousBucketCompanyId
                        )
                    )
                ) {
                    continue;
                }

                $resourceTz = $timezone
                    ?? ($resource ? ($resource->timezone ?: null) : null)
                    ?? $this->timezone;
                $resourceId = $resource?->id;

                $tzKey = $resourceTz.'_start';

                if (! isset($tzDates[$tzKey])) {
                    $tzDates[$tzKey] = $startDt->clone()->setTimezone($resourceTz);
                }

                $start = $tzDates[$tzKey];

                $tzKey = $resourceTz.'_end';

                if (! isset($tzDates[$tzKey])) {
                    $tzDates[$tzKey] = $endDt->clone()->setTimezone($resourceTz);
                }

                $end = $tzDates[$tzKey];

                $dt0 = Carbon::parse($leaveDateFrom)->setTimezone($resourceTz);

                $dt1 = Carbon::parse($leaveDateTo)->setTimezone($resourceTz);

                $result[$resourceId][] = [
                    Carbon::parse(max($start, $dt0)),
                    Carbon::parse(min($end, $dt1)),
                    $leave,
                ];
            }
        }

        $finalResult = [];

        foreach ($resourcesList as $resource) {
            $resourceId = $resource?->id;

            $finalResult[$resourceId] = collect($result[$resourceId] ?? []);
        }

        return $finalResult;
    }

    protected function applyFilters($query, array $filters): void
    {
        foreach ($filters as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            [$column, $operator, $value] = $condition;

            match ($operator) {
                'in'     => $query->whereIn($column, $value),
                'not in' => $query->whereNotIn($column, $value),
                default  => $query->where($column, $operator, $value),
            };
        }
    }

    protected function subtractIntervals($attendance, $leaves): array
    {
        $result = [];

        foreach ($attendance as [$start, $stop, $rec]) {
            $remaining = [[$start, $stop, $rec]];

            foreach ($leaves as [$leaveStart, $leaveStop]) {
                $split = [];

                foreach ($remaining as [$s, $e, $r]) {
                    if ($s->lt($leaveStart)) {
                        $split[] = [$s->copy(), ($e->lt($leaveStart) ? $e : $leaveStart)->copy(), $r];
                    }

                    if ($e->gt($leaveStop)) {
                        $split[] = [($s->gt($leaveStop) ? $s : $leaveStop)->copy(), $e->copy(), $r];
                    }
                }

                $remaining = $split;
            }

            array_push($result, ...$remaining);
        }

        return $result;
    }
}
