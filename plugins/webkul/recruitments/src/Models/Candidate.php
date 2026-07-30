<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Webkul\Chatter\Traits\HasChatter;
use Webkul\Chatter\Traits\HasLogActivity;
use Webkul\Employee\Models\Employee;
use Webkul\Partner\Models\Partner;
use Webkul\Recruitment\Database\Factories\CandidateFactory;
use Webkul\Recruitment\Models\Concerns\GuardsCompanyLifecycleOnSoftDelete;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\HasStrictCompanyId;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

/**
 * HasCompanyScope + HasStrictCompanyId (#138 PR4 A4E) — exposes direct
 * listings of personal information, the second highest-risk real gap
 * left in recruitments. manager_id validated by membership (User has no
 * single company_id); employee_id, when present, validated against the
 * already-authorized company_id. partner_id is model-managed exactly
 * like Employee's own contract (#138 PR4 A4D): once linked, it can never
 * be replaced by a different Partner nor cleared to null by an external
 * write — only handlePartnerCreation()/handlePartnerUpdation() below may
 * ever set it.
 */
class Candidate extends Model
{
    use GuardsCompanyLifecycleOnSoftDelete, HasChatter, HasCompanyScope, HasFactory, HasLogActivity, HasStrictCompanyId, SoftDeletes, ValidatesRelatedCompanyScope;

    public const ACTIVITY_PLAN_PLUGIN = 'recruitments';

    protected $table = 'recruitments_candidates';

    protected $fillable = [
        'message_bounced',
        'company_id',
        'partner_id',
        'degree_id',
        'manager_id',
        'employee_id',
        'creator_id',
        'email_cc',
        'name',
        'email_from',
        'priority',
        'phone',
        'linkedin_profile',
        'availability_date',
        'candidate_properties',
        'is_active',
    ];

    protected $casts = [
        'candidate_properties' => 'array',
        'is_active'            => 'boolean',
    ];

    public function getLogAttributeLabels(): array
    {
        return [
            'company.name'      => __('recruitments::models/candidate.log-attributes.company'),
            'partner.name'      => __('recruitments::models/candidate.log-attributes.contact'),
            'degree.name'       => __('recruitments::models/candidate.log-attributes.degree'),
            'user.name'         => __('recruitments::models/candidate.log-attributes.manager'),
            'employee.name'     => __('recruitments::models/candidate.log-attributes.employee'),
            'creator.name'      => __('recruitments::models/candidate.log-attributes.creator'),
            'phone_sanitized'   => __('recruitments::models/candidate.log-attributes.phone'),
            'email_normalized'  => __('recruitments::models/candidate.log-attributes.email'),
            'email_cc'          => __('recruitments::models/candidate.log-attributes.email_cc'),
            'name'              => __('recruitments::models/candidate.log-attributes.name'),
            'email_from'        => __('recruitments::models/candidate.log-attributes.email_from'),
            'phone'             => __('recruitments::models/candidate.log-attributes.phone_raw'),
            'linkedin_profile'  => __('recruitments::models/candidate.log-attributes.linkedin_profile'),
            'availability_date' => __('recruitments::models/candidate.log-attributes.availability_date'),
            'is_active'         => __('recruitments::models/candidate.log-attributes.is_active'),
        ];
    }

    public function getModelTitle(): string
    {
        return __('recruitments::models/candidate.title');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function degree()
    {
        return $this->belongsTo(Degree::class, 'degree_id');
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function categories()
    {
        // ->using(CandidateApplicantCategory::class): without it, attach()/
        // detach() run a raw query-builder insert/delete on the pivot
        // table, bypassing every company-scope guard
        // CandidateApplicantCategory declares entirely (#138 PR4 A4E).
        return $this->belongsToMany(ApplicantCategory::class, 'recruitments_candidate_applicant_categories', 'candidate_id', 'category_id')
            ->using(CandidateApplicantCategory::class);
    }

    public function skills(): HasMany
    {
        return $this->hasMany(CandidateSkill::class, 'candidate_id');
    }

    public function createEmployee()
    {
        $employee = $this->employee()->create([
            'name'          => $this->name,
            'user_id'       => $this->user_id,
            'department_id' => $this->department_id,
            'company_id'    => $this->company_id,
            'partner_id'    => $this->partner_id,
            'work_email'    => $this->email_from,
            'mobile_phone'  => $this->phone,
            'is_active'     => true,
        ]);

        $this->update([
            'employee_id' => $employee->id,
        ]);

        return $employee;
    }

    /**
     * User has no single company_id column of its own — duplicated
     * locally rather than shared across files, matching the employees
     * family's own convention (#138 PR4 A4E).
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
        // $candidate->company_id is already resolved/authorized by the
        // time these checks run.
        static::saving(function (self $candidate) {
            static::assertUserBelongsToCompany($candidate->manager_id, $candidate->company_id, 'Manager');
            static::assertRelatedBelongsToCompany($candidate->employee_id, Employee::class, 'Employee', $candidate->company_id);

            // partner_id is managed exclusively by handlePartnerCreation()/
            // handlePartnerUpdation() below — once linked, it must never be
            // replaced (including cleared to null) by a request pointing at
            // an arbitrary, unrelated Partner identity, or unlinked entirely
            // (#138 PR4 A4D review 4811425870, finding 3 — applied here from
            // the start). The internal flow only ever sets partner_id when
            // it was previously null, so this cannot conflict with the
            // nested save it performs.
            $originalPartnerId = $candidate->getOriginal('partner_id');

            if ($originalPartnerId !== null && (int) $originalPartnerId !== (int) $candidate->partner_id) {
                throw new AuthorizationException("Changing a Candidate's linked Partner is forbidden — it is managed automatically.");
            }
        });

        static::creating(function ($candidate) {
            $authUser = Auth::user();

            $candidate->creator_id ??= Auth::id();

            $candidate->company_id ??= $authUser?->default_company_id;
        });

        static::saved(function (self $candidate) {
            if (! $candidate->partner_id) {
                $candidate->handlePartnerCreation($candidate);
            } else {
                $candidate->handlePartnerUpdation($candidate);
            }
        });
    }

    /**
     * Dormant pre-existing bug, found and fixed here (#138 PR4 A4E): the
     * creator_id fallback used to be `Auth::user()->id ?? $candidate->id`
     * — reading a property off null degrades to a warning, not a fatal
     * error, so with no authenticated actor (e.g. a fixture created
     * inside CompanyContext::runForAllCompanies()) this silently fell all
     * the way through to $candidate->id, the Candidate's own primary
     * key, not a user id at all — violating partners_partners'
     * creator_id foreign key the moment that id didn't coincidentally
     * also belong to a real user. Falls back to the Candidate's own
     * already-resolved creator_id instead, which is itself nullable.
     */
    private function handlePartnerCreation(self $candidate)
    {
        $partner = $candidate->partner()->create([
            'creator_id' => Auth::id() ?? $candidate->creator_id,
            'sub_type'   => 'partner',
            'company_id' => $candidate->company_id,
            'phone'      => $candidate->phone,
            'email'      => $candidate->email_from,
            'name'       => $candidate->name,
        ]);

        $candidate->partner_id = $partner->id;
        $candidate->save();
    }

    private function handlePartnerUpdation(self $candidate)
    {
        $partner = Partner::updateOrCreate(
            ['id' => $candidate->partner_id],
            [
                'creator_id' => Auth::id() ?? $candidate->creator_id,
                'sub_type'   => 'partner',
                'company_id' => $candidate->company_id,
                'phone'      => $candidate->phone,
                'email'      => $candidate->email_from,
                'name'       => $candidate->name,
            ]
        );

        if ($candidate->partner_id !== $partner->id) {
            $candidate->partner_id = $partner->id;
            $candidate->save();
        }
    }

    /**
     * Dormant since this model never had HasFactory before this wave —
     * without an explicit override, Laravel's default factory-name guess
     * only handles models under the app's own root namespace (never
     * matches Webkul\...), producing an unrelated, nonexistent class name
     * instead of CandidateFactory (#138 PR4 A4E).
     */
    protected static function newFactory(): CandidateFactory
    {
        return CandidateFactory::new();
    }
}
