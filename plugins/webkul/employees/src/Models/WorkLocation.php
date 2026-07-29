<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Database\Factories\WorkLocationFactory;
use Webkul\Employee\Enums\WorkLocation as WorkLocationEnum;
use Webkul\Employee\Models\Concerns\GuardsCompanyLifecycleOnSoftDelete;
use Webkul\Field\Traits\HasCustomFields;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\HasStrictCompanyId;

/**
 * HasCompanyScope + HasStrictCompanyId (#138 PR4 A4D) — no tenant-aware FK
 * relations beyond company_id itself to validate.
 */
class WorkLocation extends Model
{
    use GuardsCompanyLifecycleOnSoftDelete, HasCompanyScope, HasCustomFields, HasFactory, HasStrictCompanyId, SoftDeletes;

    protected $table = 'employees_work_locations';

    protected $fillable = [
        'company_id',
        'creator_id',
        'name',
        'location_type',
        'location_number',
        'is_active',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'location_type'  => WorkLocationEnum::class,
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($workLocation) {
            $workLocation->creator_id ??= Auth::id();
        });
    }

    protected static function newFactory(): WorkLocationFactory
    {
        return WorkLocationFactory::new();
    }
}
