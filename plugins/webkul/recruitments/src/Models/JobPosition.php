<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeJobPosition as BaseJobPosition;
use Webkul\Employee\Models\Skill;
use Webkul\Partner\Models\Industry;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\User;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

/**
 * manager_id is code this alias adds on top of the owner (EmployeeJobPosition)
 * — not inherited, so it needs its own validation here (#138 PR4 A4D).
 * address_id/industry_id keep their existing global contract unchanged
 * (Partner is global_party_identity, Industry is a global catalog).
 */
class JobPosition extends BaseJobPosition
{
    use ValidatesRelatedCompanyScope;

    public function __construct(array $attributes = [])
    {
        $this->mergeFillable([
            'address_id',
            'manager_id',
            'industry_id',
            'recruiter_id',
            'no_of_hired_employee',
            'date_from',
            'date_to',
        ]);

        $this->mergeCasts([
            'date_from' => 'datetime',
            'date_to'   => 'datetime',
        ]);

        parent::__construct($attributes);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'address_id')->where('sub_type', 'company');
    }

    public function skills()
    {
        return $this->belongsToMany(Skill::class, 'job_position_skills', 'job_position_id', 'skill_id');
    }

    public function interviewers()
    {
        // ->using(JobPositionInterviewer::class): without it, attach()/
        // detach() run a raw query-builder insert/delete on the pivot
        // table, bypassing every company-scope/membership guard
        // JobPositionInterviewer declares entirely (#138 PR4 A4E).
        return $this->belongsToMany(User::class, 'recruitments_job_position_interviewers', 'job_position_id', 'user_id')
            ->using(JobPositionInterviewer::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function recruiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recruiter_id');
    }

    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industry::class, 'industry_id');
    }

    protected function noOfEmployee(): Attribute
    {
        return Attribute::make(
            get: function () {
                return once(function () {
                    return $this->employees()
                        ->where('is_active', true)
                        ->count();
                });
            }
        );
    }

    protected function noOfHiredEmployee(): Attribute
    {
        return Attribute::make(
            get: function () {
                return once(function () {
                    return $this->applications()
                        ->where(function ($query) {
                            $query->whereNotNull('date_closed')
                                ->where('is_active', true);
                        })
                        ->count();
                });
            }
        );
    }

    protected function expectedEmployees(): Attribute
    {
        return Attribute::make(
            get: function () {
                $currentEmployees = $this->getAttributeValue('no_of_employee');

                return $currentEmployees + ($this->no_of_recruitment ?? 0);
            }
        );
    }

    public function applications()
    {
        return $this->hasMany(Applicant::class, 'job_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function (self $jobPosition) {
            static::assertRelatedBelongsToCompany($jobPosition->manager_id, Employee::class, 'Manager', $jobPosition->company_id);
        });

        static::updated(function ($jobPosition) {
            cache()->forget("job_position_{$jobPosition->id}_employee_count");
            cache()->forget("job_position_{$jobPosition->id}_hired_count");
        });
    }
}
