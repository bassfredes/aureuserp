<?php

namespace Webkul\Account\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

class MoveReversal extends Model
{
    use HasCompanyScope, ValidatesRelatedCompanyScope;

    protected $table = 'accounts_accounts_move_reversals';

    protected $fillable = [
        'reason',
        'date',
        'journal_id',
        'company_id',
        'creator_id',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function journal()
    {
        return $this->belongsTo(Journal::class);
    }

    public function newMoves()
    {
        return $this->belongsToMany(Move::class, 'accounts_accounts_move_reversal_new_move', 'reversal_id', 'new_move_id');
    }

    public function moves()
    {
        return $this->belongsToMany(Move::class, 'accounts_accounts_move_reversal_move', 'reversal_id', 'move_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($moveReversal) {
            $authUser = Auth::user();

            $moveReversal->creator_id ??= $authUser?->id;

            $moveReversal->company_id ??= $authUser?->default_company_id;
        });

        static::saving(function ($moveReversal) {
            // Once a Journal is chosen for this reversal wizard, an
            // explicit company_id can never contradict it — the acting
            // user's default_company_id above is only a convenience
            // default before that choice is made, never an override of it
            // (#138 review, 2026-07-18). moves()/newMoves() are populated
            // post-creation via a pivot (a wizard flow, not a
            // persisted-from-scratch aggregate) and are not re-validated
            // here — that would require hooking the controller's own
            // attach()/sync() call sites, out of this rollout's scope.
            if ($moveReversal->journal_id) {
                $moveReversal->company_id = static::resolveEffectiveCompanyIdOrFail(
                    $moveReversal->journal_id,
                    Journal::class,
                    $moveReversal->company_id,
                    'Journal'
                );
            }
        });
    }
}
