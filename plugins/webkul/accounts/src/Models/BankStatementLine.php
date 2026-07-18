<?php

namespace Webkul\Account\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

class BankStatementLine extends Model
{
    use HasCompanyScope, HasFactory, ValidatesRelatedCompanyScope;

    protected $table = 'accounts_bank_statement_lines';

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'statement_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function (self $line): void {
            // Always re-derived from the parent BankStatement — a missing
            // statement or a statement with no company of its own is a
            // hard failure, and a statement_id reassignment that resolves
            // to a different company than this line's current one is
            // rejected outright, not silently moved (#138 review,
            // 2026-07-18).
            $line->company_id = static::resolveEffectiveCompanyIdOrFail($line->statement_id, BankStatement::class, $line->company_id, 'Bank Statement');
        });
    }
}
