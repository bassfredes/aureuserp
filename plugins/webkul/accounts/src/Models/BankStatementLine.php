<?php

namespace Webkul\Account\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Support\Traits\HasCompanyScope;

class BankStatementLine extends Model
{
    use HasCompanyScope, HasFactory;

    protected $table = 'accounts_bank_statement_lines';

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'statement_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function (self $line): void {
            // Always synced from the parent BankStatement, never left
            // unset — an unset company_id would make this row invisible
            // under HasCompanyScope's strict whereIn (#138 audit follow-up,
            // D5b pattern, aureuserp#137).
            if ($line->statement_id) {
                $line->company_id = $line->statement?->company_id;
            }
        });
    }
}
