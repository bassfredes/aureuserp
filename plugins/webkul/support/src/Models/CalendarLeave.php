<?php

namespace Webkul\Support\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Security\Models\User;
use Webkul\Support\Database\Factories\CalendarLeaveFactory;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\HasCompanyScope;

/**
 * strict_company (#138 A4I): no shared CalendarLeave rows exist in this
 * codebase — zero rows tagged company_id IS NULL after a fresh install, no
 * seeder produces one — and the previous calendar_id IS NULL wildcard in
 * Calendar::getLeaveIntervalsBatch() (now removed) was a live
 * cross-company leak, not a feature. Both company_id and calendar_id are
 * derived and immutable once persisted: when resource_type/resource_id
 * point at a WorkCenter (the only allowed resource — no morph map is
 * registered anywhere in this codebase, so resource_type is compared
 * against the literal FQCN), the company and calendar are taken from that
 * WorkCenter and must not conflict with an explicitly-supplied calendar_id.
 * Without a resource, the company derives from the target Calendar when it
 * is company-owned; a shared (company_id IS NULL) Calendar requires an
 * explicit, write-authorized company_id — there is no shared-leave concept
 * here, only a shared *calendar* that individual companies' leaves may
 * still attach to.
 */
class CalendarLeave extends Model
{
    use HasCompanyScope, HasFactory;

    /**
     * Compared against the literal resource_type value — no morph map is
     * registered for this table anywhere in the codebase.
     */
    private const ALLOWED_RESOURCE_TYPES = [
        'Webkul\\Manufacturing\\Models\\WorkCenter',
    ];

    protected $table = 'calendar_leaves';

    protected $fillable = [
        'name',
        'time_type',
        'date_from',
        'date_to',
        'company_id',
        'calendar_id',
        'creator_id',
        'resource_type',
        'resource_id',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function calendar()
    {
        return $this->belongsTo(Calendar::class);
    }

    public function resource(): MorphTo
    {
        return $this->morphTo();
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    private static function assertResourcePairIsConsistent(self $calendarLeave): void
    {
        if (($calendarLeave->resource_type === null) !== ($calendarLeave->resource_id === null)) {
            throw new AuthorizationException('CalendarLeave.resource_type and resource_id must both be null or both be present.');
        }
    }

    /**
     * Bypasses CompanyScope on purpose: the resource must be resolvable
     * even when the acting actor cannot read it under normal scoping, so
     * the mismatch (or lack of one) can still be reported accurately. A
     * soft-deleted WorkCenter is deliberately NOT looked up withTrashed()
     * here — an eliminated resource must be rejected, not silently
     * tolerated (#138 A4I).
     */
    private static function resolveResource(self $calendarLeave): Model
    {
        if (! in_array($calendarLeave->resource_type, static::ALLOWED_RESOURCE_TYPES, true)) {
            throw new AuthorizationException("CalendarLeave.resource_type '{$calendarLeave->resource_type}' is not allowed.");
        }

        $resourceClass = $calendarLeave->resource_type;

        $resource = $resourceClass::withoutGlobalScope(CompanyScope::class)->find($calendarLeave->resource_id);

        if (! $resource) {
            throw new AuthorizationException('The related resource could not be found.');
        }

        return $resource;
    }

    /**
     * Derives the effective company_id and, for a resource-bearing leave,
     * overwrites calendar_id with the resource's own — mutating
     * $calendarLeave in place mirrors Leave::saving()'s own
     * company_id/employee_company_id derivation pattern. Returns the
     * resolved value so the caller can compare it against the original on
     * update without re-deriving it.
     */
    private static function resolveEffectiveCompanyId(self $calendarLeave): int
    {
        if ($calendarLeave->resource_type !== null) {
            $resource = static::resolveResource($calendarLeave);

            if ($calendarLeave->calendar_id !== null && (int) $calendarLeave->calendar_id !== (int) $resource->calendar_id) {
                throw new AuthorizationException("The calendar_id does not match the resource WorkCenter's own calendar.");
            }

            if ($calendarLeave->company_id !== null && (int) $calendarLeave->company_id !== (int) $resource->company_id) {
                throw new AuthorizationException("The company_id does not match the resource WorkCenter's own company.");
            }

            $calendarLeave->calendar_id = $resource->calendar_id;

            return (int) $resource->company_id;
        }

        if ($calendarLeave->calendar_id === null) {
            throw new AuthorizationException('A CalendarLeave requires a calendar_id (or a resource whose calendar it inherits).');
        }

        $calendar = Calendar::withoutGlobalScope(CompanyScope::class)->withTrashed()->find($calendarLeave->calendar_id);

        if (! $calendar) {
            throw new AuthorizationException('The related Calendar could not be found.');
        }

        if ($calendar->company_id !== null) {
            if ($calendarLeave->company_id !== null && (int) $calendarLeave->company_id !== (int) $calendar->company_id) {
                throw new AuthorizationException("The company_id does not match the Calendar's own company.");
            }

            return (int) $calendar->company_id;
        }

        // Shared calendar: no shared-leave concept exists here, so a
        // company_id must be supplied explicitly and write-authorized
        // below — never silently defaulted from the acting user.
        if ($calendarLeave->company_id === null) {
            throw new AuthorizationException('A CalendarLeave against a shared Calendar requires an explicit company_id.');
        }

        return (int) $calendarLeave->company_id;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $calendarLeave) {
            $calendarLeave->creator_id ??= Auth::id();
        });

        static::saving(function (self $calendarLeave) {
            static::assertResourcePairIsConsistent($calendarLeave);

            $originalCompanyId = $calendarLeave->exists ? $calendarLeave->getOriginal('company_id') : null;
            $originalCalendarId = $calendarLeave->exists ? $calendarLeave->getOriginal('calendar_id') : null;

            if ($originalCompanyId !== null) {
                CompanyScope::assertCanWriteCompany((int) $originalCompanyId);
            }

            $effectiveCompanyId = static::resolveEffectiveCompanyId($calendarLeave);

            CompanyScope::assertCanWriteCompany($effectiveCompanyId);

            if ($calendarLeave->exists) {
                if ((int) $originalCompanyId !== $effectiveCompanyId) {
                    throw new AuthorizationException('Changing the company of this CalendarLeave is forbidden — archive it and create a new one instead.');
                }

                if ($originalCalendarId !== null && (int) $originalCalendarId !== (int) $calendarLeave->calendar_id) {
                    throw new AuthorizationException('Changing the calendar of this CalendarLeave is forbidden — archive it and create a new one instead.');
                }
            }

            $calendarLeave->company_id = $effectiveCompanyId;
        });
    }

    protected static function newFactory(): CalendarLeaveFactory
    {
        return CalendarLeaveFactory::new();
    }
}
