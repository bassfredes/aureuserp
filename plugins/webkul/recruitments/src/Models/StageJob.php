<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Webkul\Employee\Models\EmployeeJobPosition;
use Webkul\Recruitment\Models\Scopes\ParentDerivedCompanyScope;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

/**
 * Pivot (not a plain Model) so Stage::jobs()'s ->using() wiring makes
 * attach()/detach() go through this class's own save()/delete(), not a
 * raw query-builder insert/delete that would bypass every guard below
 * entirely (#138 PR4 A4E). No company_id of its own — isolation and
 * authorization both derive from the JobPosition side of the pivot, NOT
 * from Stage: Stage is a global, cross-company kanban catalog
 * (global_reference) reused by every company, so the tenant-aware
 * association lives entirely here.
 */
class StageJob extends Pivot
{
    use HasFactory, ValidatesRelatedCompanyScope;

    public $timestamps = false;

    protected $table = 'recruitments_stages_jobs';

    protected $fillable = [
        'stage_id',
        'job_id',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(EmployeeJobPosition::class, 'job_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope(new ParentDerivedCompanyScope('job'));

        static::creating(function (self $pivot) {
            static::resolveEffectiveCompanyIdOrFail($pivot->job_id, EmployeeJobPosition::class, null, 'Job Position');
        });

        static::deleting(function (self $pivot) {
            static::resolveEffectiveCompanyIdOrFail($pivot->job_id, EmployeeJobPosition::class, null, 'Job Position');
        });
    }
}
