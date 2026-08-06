<?php

namespace Webkul\Employee\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Webkul\Chatter\Traits\HasChatter;
use Webkul\Chatter\Traits\HasLogActivity;
use Webkul\Employee\Database\Factories\EmployeeFactory;
use Webkul\Employee\Models\Concerns\GuardsCompanyLifecycleOnSoftDelete;
use Webkul\Field\Traits\HasCustomFields;
use Webkul\Partner\Models\BankAccount;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Country;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Models\State;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\HasStrictCompanyId;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

/**
 * Company ownership (#138 PR4 A4D): HasCompanyScope + HasStrictCompanyId
 * make Employee itself a strict_company owner. Beyond that baseline, every
 * tenant-aware FK this model carries is validated against the Employee's
 * own already-authorized company_id: department_id/job_id/work_location_id
 * (related models with their own company_id column, compared via
 * ValidatesRelatedCompanyScope), parent_id/coach_id (self-relations, same
 * mechanism), and user_id/attendance_manager_id/leave_manager_id (User has
 * no single company_id — membership is checked via
 * CompanyScope::allowedCompanyIds(), same rule the scope itself uses for
 * the acting user, applied here to the referenced User instead).
 * bank_account_id keeps its ola4B contract (BankAccount::assertEnabledForCompany())
 * unchanged, now running against an already-authorized company_id.
 */
class Employee extends Model
{
    use GuardsCompanyLifecycleOnSoftDelete, HasChatter, HasCompanyScope, HasCustomFields, HasFactory, HasLogActivity, HasStrictCompanyId, SoftDeletes, ValidatesRelatedCompanyScope;

    public const ACTIVITY_PLAN_PLUGIN = 'employees';

    protected $table = 'employees_employees';

    protected $fillable = [
        'company_id',
        'user_id',
        'creator_id',
        'calendar_id',
        'department_id',
        'job_id',
        'attendance_manager_id',
        'partner_id',
        'work_location_id',
        'parent_id',
        'coach_id',
        'country_id',
        'state_id',
        'country_of_birth',
        'bank_account_id',
        'departure_reason_id',
        'name',
        'job_title',
        'work_phone',
        'mobile_phone',
        'color',
        'work_email',
        'children',
        'distance_home_work',
        'km_home_work',
        'distance_home_work_unit',
        'private_phone',
        'private_email',
        'private_street1',
        'private_street2',
        'private_city',
        'private_zip',
        'private_state_id',
        'private_country_id',
        'private_car_plate',
        'lang',
        'gender',
        'birthday',
        'marital',
        'spouse_complete_name',
        'spouse_birthdate',
        'place_of_birth',
        'ssnid',
        'sinid',
        'identification_id',
        'passport_id',
        'permit_no',
        'visa_no',
        'certificate',
        'study_field',
        'study_school',
        'emergency_contact',
        'emergency_phone',
        'employee_type',
        'barcode',
        'pin',
        'address_id',
        'time_zone',
        'work_permit',
        'leave_manager_id',
        'visa_expire',
        'work_permit_expiration_date',
        'departure_date',
        'departure_description',
        'additional_note',
        'notes',
        'is_active',
        'is_flexible',
        'is_fully_flexible',
        'work_permit_scheduled_activity',
    ];

    protected $casts = [
        'is_active'                      => 'boolean',
        'is_flexible'                    => 'boolean',
        'is_fully_flexible'              => 'boolean',
        'work_permit_scheduled_activity' => 'boolean',
    ];

    public function getModelTitle(): string
    {
        return __('employees::models/employee.title');
    }

    public function privateState(): BelongsTo
    {
        return $this->belongsTo(State::class, 'private_state_id');
    }

    public function privateCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'private_country_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class, 'calendar_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(EmployeeJobPosition::class, 'job_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class, 'work_location_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(self::class, 'coach_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class, 'state_id');
    }

    public function countryOfBirth(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_of_birth');
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function departureReason(): BelongsTo
    {
        return $this->belongsTo(DepartureReason::class, 'departure_reason_id');
    }

    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(EmploymentType::class, 'employee_type');
    }

    public function categories()
    {
        return $this->belongsToMany(EmployeeCategory::class, 'employees_employee_categories', 'employee_id', 'category_id');
    }

    public function skills(): HasMany
    {
        return $this->hasMany(EmployeeSkill::class, 'employee_id');
    }

    public function resumes()
    {
        return $this->hasMany(EmployeeResume::class, 'employee_id');
    }

    protected static function newFactory(): EmployeeFactory
    {
        return EmployeeFactory::new();
    }

    public function leaveManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leave_manager_id');
    }

    public function attendanceManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendance_manager_id');
    }

    public function companyAddress()
    {
        return $this->belongsTo(Partner::class, 'address_id');
    }

    /**
     * User has no single authoritative company_id — membership is
     * "default_company_id + allowedCompanies() pivot", the same rule
     * CompanyScope::allowedCompanyIds() already applies to the acting
     * user, applied here to a referenced User instead. Always re-fetches
     * the User fresh, never trusts an in-memory relation object.
     */
    private static function assertUserBelongsToCompany(?int $userId, ?int $companyId, string $label): void
    {
        if ($userId === null) {
            return;
        }

        $user = User::find($userId);

        if (! $user || $companyId === null || ! CompanyScope::allowedCompanyIds($user)->contains((int) $companyId)) {
            throw new AuthorizationException("The related {$label} is not a member of this Employee's company.");
        }
    }

    /**
     * Calendar is company_or_shared (#138 A4I), unlike Department/JobPosition
     * /WorkLocation/self — assertRelatedBelongsToCompany() fails closed on a
     * NULL company on either side, which would reject the seeded shared
     * default calendar for every Employee. A shared (company_id IS NULL)
     * Calendar is always assignable; a company-owned one must match. A
     * soft-deleted Calendar is only rejected for a NEW assignment — an
     * Employee already pointing at one (e.g. via WorkCenter::calendar()'s
     * own withTrashed() precedent) keeps resolving it.
     */
    private static function assertCalendarIsAssignable(?int $calendarId, ?int $companyId, bool $isNewAssignment): void
    {
        if ($calendarId === null) {
            return;
        }

        $calendar = Calendar::withoutGlobalScope(CompanyScope::class)->withTrashed()->find($calendarId);

        if (! $calendar) {
            throw new AuthorizationException('The related Calendar does not exist.');
        }

        if ($isNewAssignment && $calendar->trashed()) {
            throw new AuthorizationException('The related Calendar has been deleted and cannot be newly assigned.');
        }

        if ($calendar->company_id === null) {
            return;
        }

        if ($companyId === null || (int) $calendar->company_id !== (int) $companyId) {
            throw new AuthorizationException('The related Calendar belongs to a different company.');
        }
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function (self $employee) {
            // Runs after HasStrictCompanyId's own `saving` listener (trait
            // boot order: parent::boot() registers it first), so
            // $employee->company_id is already resolved/authorized by the
            // time these relation checks run.
            static::assertRelatedBelongsToCompany($employee->department_id, Department::class, 'Department', $employee->company_id);
            static::assertRelatedBelongsToCompany($employee->job_id, EmployeeJobPosition::class, 'Job Position', $employee->company_id);
            static::assertRelatedBelongsToCompany($employee->work_location_id, WorkLocation::class, 'Work Location', $employee->company_id);
            static::assertCalendarIsAssignable($employee->calendar_id, $employee->company_id, ! $employee->exists || $employee->isDirty('calendar_id'));

            if ($employee->exists && $employee->parent_id !== null && (int) $employee->parent_id === (int) $employee->id) {
                throw new AuthorizationException('An Employee cannot be its own parent/manager.');
            }

            static::assertRelatedBelongsToCompany($employee->parent_id, self::class, 'Parent/Manager', $employee->company_id);
            static::assertRelatedBelongsToCompany($employee->coach_id, self::class, 'Coach', $employee->company_id);

            static::assertUserBelongsToCompany($employee->user_id, $employee->company_id, 'Related User');
            static::assertUserBelongsToCompany($employee->attendance_manager_id, $employee->company_id, 'Attendance Manager');
            static::assertUserBelongsToCompany($employee->leave_manager_id, $employee->company_id, 'Leave Manager');

            // partner_id is managed exclusively by handlePartnerCreation()/
            // handlePartnerUpdation() below — once linked, it must never be
            // replaced (including cleared to null) by a request pointing at
            // an arbitrary, unrelated Partner identity, or unlinked entirely
            // (#138 PR4 A4D review 4811425870, finding 3 — the previous
            // check exempted the existing-to-null transition, which let a
            // cleared partner_id silently trigger handlePartnerCreation()
            // into creating a brand new Partner, replacing the "immutable"
            // link). The internal flow only ever sets partner_id when it
            // was previously null, so this cannot conflict with the nested
            // save it performs.
            $originalPartnerId = $employee->getOriginal('partner_id');

            if ($originalPartnerId !== null && (int) $originalPartnerId !== (int) $employee->partner_id) {
                throw new AuthorizationException("Changing an Employee's linked Partner is forbidden — it is managed automatically.");
            }

            // bank_account_id must be enabled for this Employee's own
            // company (#138 PR4 ola4B, approved contract) — BankAccount
            // has no company_id of its own, only a membership pivot.
            BankAccount::assertEnabledForCompany($employee->bank_account_id, $employee->company_id, 'Employee Bank Account');
        });

        static::saved(function (self $employee) {
            $employee->creator_id ??= Auth::id();

            if (! $employee->partner_id) {
                $employee->handlePartnerCreation($employee);
            } else {
                $employee->handlePartnerUpdation($employee);
            }
        });
    }

    private function handlePartnerCreation(self $employee): void
    {
        $partner = $employee->partner()->create([
            'account_type' => 'individual',
            'sub_type'     => 'employee',
            'creator_id'   => $employee->creator_id ?? Auth::id(),
            'name'         => $employee?->name,
            'email'        => $employee?->work_email ?? $employee?->private_email,
            'job_title'    => $employee?->job_title,
            'phone'        => $employee?->work_phone,
            'mobile'       => $employee?->mobile_phone,
            'color'        => $employee?->color,
            // The manager's own Partner id, not the manager's Employee id
            // (partners_partners.parent_id references other partners) —
            // pre-existing bug (#138 PR4 A4D), dormant until parent_id was
            // ever set to a real Employee id whose numeric value didn't
            // also happen to be a valid partner id.
            'parent_id'    => $employee?->parent?->partner_id,
            'company_id'   => $employee?->company_id,
            'user_id'      => $employee?->user_id,
        ]);

        $employee->partner_id = $partner->id;
        $employee->save();
    }

    private function handlePartnerUpdation(self $employee): void
    {
        $partner = Partner::updateOrCreate(
            ['id' => $employee->partner_id],
            [
                'account_type' => 'individual',
                'sub_type'     => 'employee',
                'creator_id'   => $employee->creator_id ?? Auth::id(),
                'name'         => $employee?->name,
                'email'        => $employee?->work_email ?? $employee?->private_email,
                'job_title'    => $employee?->job_title,
                'phone'        => $employee?->work_phone,
                'mobile'       => $employee?->mobile_phone,
                'color'        => $employee?->color,
                'parent_id'    => $employee?->parent?->partner_id,
                'company_id'   => $employee?->company_id,
                'user_id'      => $employee?->user_id,
            ]
        );

        if ($employee->partner_id !== $partner->id) {
            $employee->partner_id = $partner->id;
            $employee->save();
        }
    }
}
