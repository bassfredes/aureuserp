<?php

namespace Webkul\Account\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
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
            $moveReversal->creator_id ??= Auth::id();
        });

        static::saving(function ($moveReversal) {
            // strict_company, always non-null — a company_id is required
            // whether or not a Journal is chosen yet, unlike the earlier
            // "only when journal_id present" contract (#138 review round
            // 3, 2026-07-18: MoveReversal could otherwise persist with
            // company_id NULL, or an unauthorized explicit company_id,
            // when created without a Journal).
            if ($moveReversal->journal_id) {
                $resolvedCompanyId = static::resolveEffectiveCompanyIdOrFail(
                    $moveReversal->journal_id,
                    Journal::class,
                    $moveReversal->company_id,
                    'Journal'
                );

                if ($moveReversal->exists) {
                    $originalCompanyId = $moveReversal->getOriginal('company_id');

                    if ($originalCompanyId !== null && (int) $originalCompanyId !== $resolvedCompanyId) {
                        throw new AuthorizationException('Changing the company of this record is forbidden — archive it and create a new one instead.');
                    }
                }

                $moveReversal->company_id = $resolvedCompanyId;

                return;
            }

            if ($moveReversal->exists && $moveReversal->isDirty('company_id')) {
                throw new AuthorizationException('Changing the company of this record is forbidden — archive it and create a new one instead.');
            }

            if (! $moveReversal->exists) {
                $moveReversal->company_id ??= Auth::user()?->default_company_id;

                if ($moveReversal->company_id === null) {
                    throw new AuthorizationException('MoveReversal requires a company_id (directly, or via a Journal) and none could be resolved.');
                }
            }

            // Re-authorize the effective company on every save, not only
            // on create or when it changes — an actor who obtained a
            // cross-company MoveReversal via an unscoped query must not be
            // able to write any other field on it (#138 review round 3,
            // 2026-07-18).
            CompanyScope::assertCanWriteCompany((int) $moveReversal->company_id);
        });
    }

    /**
     * The only sanctioned way to attach a Move to this reversal's
     * `moves`/`newMoves` pivots — validates the Move belongs to this
     * reversal's own (already strict, non-null) company before the pivot
     * row is ever written, closing the gap a controller-level check alone
     * would leave open for any other write path (#138 review round 3,
     * 2026-07-18).
     */
    public function attachMove(Move $move): void
    {
        $this->assertMoveBelongsToCompany($move, 'Move');

        $this->moves()->attach($move->getKey());
    }

    public function attachNewMove(Move $move): void
    {
        $this->assertMoveBelongsToCompany($move, 'New Move');

        $this->newMoves()->attach($move->getKey());
    }

    private function assertMoveBelongsToCompany(Move $move, string $label): void
    {
        if ($this->company_id === null || (int) $move->company_id !== (int) $this->company_id) {
            throw new AuthorizationException("The related {$label} belongs to a different company.");
        }
    }
}
