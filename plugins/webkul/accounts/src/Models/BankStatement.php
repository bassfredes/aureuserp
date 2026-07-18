<?php

namespace Webkul\Account\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

class BankStatement extends Model
{
    use HasCompanyScope, HasFactory, ValidatesRelatedCompanyScope;

    protected $table = 'accounts_bank_statements';

    protected $fillable = [
        'company_id',
        'journal_id',
        'creator_id',
        'name',
        'reference',
        'first_line_index',
        'date',
        'balance_start',
        'balance_end',
        'balance_end_real',
        'is_completed',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function journal()
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($bankStatement) {
            $bankStatement->creator_id ??= Auth::id();
        });

        static::saving(function ($bankStatement) {
            // Journal is the authoritative anchor when set — an explicit
            // company_id can never contradict it. Without a journal yet
            // (draft), company_id falls back to the acting user's default
            // but can never persist NULL (#138 review, 2026-07-18).
            if ($bankStatement->journal_id) {
                $bankStatement->company_id = static::resolveEffectiveCompanyIdOrFail($bankStatement->journal_id, Journal::class, $bankStatement->company_id, 'Journal');

                return;
            }

            $bankStatement->company_id ??= Auth::user()?->default_company_id;

            if ($bankStatement->company_id === null) {
                throw new AuthorizationException('BankStatement requires a company_id (directly, or via a Journal) and none could be resolved.');
            }
        });
    }
}
