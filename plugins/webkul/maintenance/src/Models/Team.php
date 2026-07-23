<?php

namespace Webkul\Maintenance\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Webkul\Maintenance\Database\Factories\TeamFactory;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\HasStrictCompanyId;

class Team extends Model
{
    use HasCompanyScope, HasFactory, HasStrictCompanyId, SoftDeletes;

    protected $table = 'maintenance_teams';

    protected $fillable = [
        'name',
        'creator_id',
        'company_id',
        'deleted_at',
    ];

    public function getModelTitle(): string
    {
        return __('maintenance::models/team.title');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function equipments(): HasMany
    {
        return $this->hasMany(Equipment::class, 'maintenance_team_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class, 'maintenance_team_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'maintenance_team_users', 'team_id', 'user_id');
    }

    protected static function newFactory(): TeamFactory
    {
        return TeamFactory::new();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $team): void {
            $team->creator_id ??= Auth::id();
        });
    }
}
