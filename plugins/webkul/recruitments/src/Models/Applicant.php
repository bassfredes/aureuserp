<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Webkul\Chatter\Traits\HasChatter;
use Webkul\Chatter\Traits\HasLogActivity;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Recruitment\Database\Factories\ApplicantFactory;
use Webkul\Recruitment\Enums\ApplicationStatus;
use Webkul\Recruitment\Models\Concerns\GuardsCompanyLifecycleOnSoftDelete;
use Webkul\Recruitment\Traits\HasApplicationStatus;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Models\UTMMedium;
use Webkul\Support\Models\UTMSource;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\HasStrictCompanyId;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

/**
 * HasCompanyScope + HasStrictCompanyId (#138 PR4 A4E) — Applicant exposes
 * direct listings of personal/salary information, the highest-risk real
 * gap left in recruitments. candidate_id/job_id/department_id validated
 * against the already-authorized company_id (ValidatesRelatedCompanyScope);
 * recruiter_id validated by membership, since User has no single
 * company_id of its own. stage_id/last_stage_id are left unvalidated on
 * purpose: Stage is a global catalog (global_reference), reused across
 * companies by design — the tenant-aware association lives on StageJob,
 * derived from JobPosition, not on Stage itself.
 */
class Applicant extends Model
{
    use GuardsCompanyLifecycleOnSoftDelete, HasApplicationStatus, HasChatter, HasCompanyScope, HasFactory, HasLogActivity, HasStrictCompanyId, SoftDeletes, ValidatesRelatedCompanyScope;

    public const ACTIVITY_PLAN_PLUGIN = 'recruitments';

    protected $table = 'recruitments_applicants';

    protected $fillable = [
        'source_id',
        'medium_id',
        'candidate_id',
        'stage_id',
        'last_stage_id',
        'company_id',
        'recruiter_id',
        'job_id',
        'department_id',
        'refuse_reason_id',
        'state',
        'creator_id',
        'email_cc',
        'priority',
        'salary_proposed_extra',
        'salary_expected_extra',
        'applicant_properties',
        'applicant_notes',
        'is_active',
        'create_date',
        'date_closed',
        'date_opened',
        'date_last_stage_updated',
        'refuse_date',
        'probability',
        'salary_proposed',
        'salary_expected',
        'delay_close',
    ];

    protected $casts = [
        'is_active'               => 'boolean',
        'create_date'             => 'date',
        'date_closed'             => 'date',
        'date_opened'             => 'date',
        'date_last_stage_updated' => 'date',
        'refuse_date'             => 'date',
        'applicant_properties'    => 'json',
        'probability'             => 'double',
        'salary_proposed'         => 'double',
        'salary_expected'         => 'double',
        'delay_close'             => 'double',
    ];

    protected $appends = [
        'application_status',
    ];

    public function getModelTitle(): string
    {
        return __('recruitments::models/applicant.title');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(UTMSource::class);
    }

    public function medium(): BelongsTo
    {
        return $this->belongsTo(UTMMedium::class);
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function skills(): HasManyThrough
    {
        return $this->hasManyThrough(
            CandidateSkill::class,
            Candidate::class,
            'id',
            'candidate_id',
            'candidate_id',
            'id'
        );
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function lastStage(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'last_stage_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function recruiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recruiter_id');
    }

    public function interviewer()
    {
        // ->using(ApplicantInterviewer::class): without it, attach()/
        // detach() run a raw query-builder insert/delete on the pivot
        // table, bypassing every company-scope/membership guard
        // ApplicantInterviewer declares entirely (#138 PR4 A4E).
        return $this->belongsToMany(User::class, 'recruitments_applicant_interviewers', 'applicant_id', 'interviewer_id')
            ->using(ApplicantInterviewer::class);
    }

    public function categories()
    {
        // ->using(ApplicantApplicantCategory::class): without it, attach()/
        // detach() run a raw query-builder insert/delete on the pivot
        // table, bypassing every company-scope guard
        // ApplicantApplicantCategory declares entirely (#138 PR4 A4E).
        return $this->belongsToMany(ApplicantCategory::class, 'recruitments_applicant_applicant_categories', 'applicant_id', 'category_id')
            ->using(ApplicantApplicantCategory::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class, 'job_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function refuseReason(): BelongsTo
    {
        return $this->belongsTo(RefuseReason::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public static function getStatusOptions(): array
    {
        return ApplicationStatus::options();
    }

    public function setAsHired(): bool
    {
        return $this->updateStatus(ApplicationStatus::HIRED->value);
    }

    public function setAsRefused(int $refuseReasonId): bool
    {
        return $this->updateStatus(ApplicationStatus::REFUSED->value, [
            'refuse_reason_id' => $refuseReasonId,
        ]);
    }

    public function setAsArchived(): bool
    {
        return $this->updateStatus(ApplicationStatus::ARCHIVED->value);
    }

    public function reopen(): bool
    {
        return $this->updateStatus(ApplicationStatus::ONGOING->value);
    }

    public function updateStage(array $data): bool
    {
        return $this->update($data);
    }

    public function getApplicationStatusAttribute(): ?ApplicationStatus
    {
        if ($this->refuse_reason_id) {
            return ApplicationStatus::REFUSED;
        } elseif (! $this->is_active || $this->deleted_at) {
            return ApplicationStatus::ARCHIVED;
        } elseif ($this->date_closed) {
            return ApplicationStatus::HIRED;
        } else {
            return ApplicationStatus::ONGOING;
        }
    }

    /**
     * The previous version assigned 'company_id' twice in the payload
     * below — once from $this->company_id, once from
     * $this->candidate->company_id — a PHP array literal silently keeps
     * only the LAST duplicate key, so the Applicant's own company_id was
     * discarded without any indication the two could ever disagree
     * (#138 PR4 A4E). A single authoritative company_id is used now, and
     * any divergence between the Applicant and its Candidate is rejected
     * outright rather than silently favoring one side.
     */
    public function createEmployee(): ?Employee
    {
        if (! $this->candidate?->partner_id) {
            return null;
        }

        if ($this->candidate->employee_id) {
            return $this->candidate->employee;
        }

        if ((int) $this->candidate->company_id !== (int) $this->company_id) {
            throw new AuthorizationException('The related Candidate belongs to a different company.');
        }

        $employee = Employee::create([
            'name'          => $this->candidate->name,
            'user_id'       => $this->candidate->user_id,
            'job_id'        => $this->job_id,
            'department_id' => $this->department_id,
            'company_id'    => $this->company_id,
            'partner_id'    => $this->candidate->partner_id,
            'work_email'    => $this->candidate->email_from,
            'mobile_phone'  => $this->candidate->phone,
            'is_active'     => true,
        ]);

        $this->candidate()->update([
            'employee_id' => $employee->id,
        ]);

        return $employee;
    }

    public function handleApplicationCreation(): void
    {
        $authUser = Auth::user();

        $this->creator_id ??= $authUser->id;

        $this->company_id ??= $authUser?->default_company_id;
    }

    public function handleApplicationUpdation(): void
    {
        $original = $this->getRawOriginal();

        if ($this->isDirty('recruiter_id')) {
            $this->date_opened = now();
        }

        if ($this->isDirty('stage_id')) {
            $this->date_last_stage_updated = now();

            if (empty($original['stage_id'])) {
                $this->notificationData = $this->getAttributes();
            } else {
                $this->last_stage_id = $original['stage_id'];
            }
        }

        if ($this->relationLoaded('interviewer')) {
            $oldInterviewers = collect(
                $this->interviewer->pluck('id')
            );

            $newInterviewers = collect(
                $this->recruitments_applicant_interviewers ?? []
            );

            if (
                $oldInterviewers->isNotEmpty()
                || $newInterviewers->isNotEmpty()
            ) {
                $this->interviewerChanges = [
                    'old' => $oldInterviewers,
                    'new' => $newInterviewers,
                ];
            }
        }
    }

    /**
     * User has no single company_id column of its own (only
     * default_company_id + the allowedCompanies() pivot) — duplicated
     * locally rather than shared across files, matching the employees
     * family's own convention of keeping each model's lifecycle concern
     * narrowly scoped (#138 PR4 A4E).
     */
    private static function assertUserBelongsToCompany(?int $userId, ?int $companyId, string $label): void
    {
        if ($userId === null) {
            return;
        }

        $user = User::find($userId);

        if (! $user) {
            return;
        }

        if ($companyId === null || ! CompanyScope::allowedCompanyIds($user)->contains((int) $companyId)) {
            throw new AuthorizationException("The related {$label} has no membership in this company.");
        }
    }

    protected static function boot()
    {
        parent::boot();

        // Runs after HasStrictCompanyId's own `saving` listener (trait
        // boot order: parent::boot() registers it first), so
        // $applicant->company_id is already resolved/authorized by the
        // time these relation checks run.
        static::saving(function (self $applicant) {
            static::assertRelatedBelongsToCompany($applicant->candidate_id, Candidate::class, 'Candidate', $applicant->company_id);
            static::assertRelatedBelongsToCompany($applicant->job_id, JobPosition::class, 'Job Position', $applicant->company_id);
            static::assertRelatedBelongsToCompany($applicant->department_id, Department::class, 'Department', $applicant->company_id);
            static::assertUserBelongsToCompany($applicant->recruiter_id, $applicant->company_id, 'Recruiter');
        });

        static::creating(function ($applicant) {
            $applicant->handleApplicationCreation();
        });

        static::updating(function ($applicant) {
            $applicant->handleApplicationUpdation();
        });
    }

    /**
     * Dormant since this model never had HasFactory before this wave —
     * without an explicit override, Laravel's default factory-name guess
     * only handles models under the app's own root namespace (never
     * matches Webkul\...), producing an unrelated, nonexistent class name
     * instead of ApplicantFactory (#138 PR4 A4E).
     */
    protected static function newFactory(): ApplicantFactory
    {
        return ApplicantFactory::new();
    }
}
