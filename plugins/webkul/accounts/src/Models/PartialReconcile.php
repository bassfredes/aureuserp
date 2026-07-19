<?php

namespace Webkul\Account\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Database\Factories\PartialReconcileFactory;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Currency;
use Webkul\Support\Traits\HasCompanyScope;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

class PartialReconcile extends Model
{
    use HasCompanyScope, HasFactory, ValidatesRelatedCompanyScope;

    protected $table = 'accounts_partial_reconciles';

    protected $fillable = [
        'debit_move_id',
        'credit_move_id',
        'full_reconcile_id',
        'exchange_move_id',
        'debit_currency_id',
        'credit_currency_id',
        'company_id',
        'creator_id',
        'max_date',
        'amount',
        'debit_amount_currency',
        'credit_amount_currency',
    ];

    public function debitMove()
    {
        return $this->belongsTo(MoveLine::class, 'debit_move_id');
    }

    public function creditMove()
    {
        return $this->belongsTo(MoveLine::class, 'credit_move_id');
    }

    public function fullReconcile()
    {
        return $this->belongsTo(FullReconcile::class, 'full_reconcile_id');
    }

    public function exchangeMove()
    {
        return $this->belongsTo(Move::class, 'exchange_move_id');
    }

    public function debitCurrency()
    {
        return $this->belongsTo(Currency::class, 'debit_currency_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function computeDebitCurrencyId()
    {
        $this->debit_currency_id = $this->debitMove->currency_id;
    }

    public function computeCreditCurrencyId()
    {
        $this->credit_currency_id = $this->creditMove->currency_id;
    }

    public function computeMaxDate()
    {
        $debitDate = $this->debitMove->move->date;

        $creditDate = $this->creditMove->move->date;

        $this->max_date = ($debitDate > $creditDate) ? $debitDate : $creditDate;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($partialReconcile) {
            $partialReconcile->creator_id ??= Auth::id();
        });

        static::saving(function ($partialReconcile) {
            $partialReconcile->computeDebitCurrencyId();

            $partialReconcile->computeCreditCurrencyId();

            $partialReconcile->computeMaxDate();

            // A reconciliation can only ever pair two MoveLines from the
            // same company — company_id is derived from the debit line
            // (never the acting user), the credit line must match it, and
            // an exchange Move (if any) must belong to it too (#138
            // review, 2026-07-18).
            $partialReconcile->company_id = static::resolveEffectiveCompanyIdOrFail(
                $partialReconcile->debit_move_id,
                MoveLine::class,
                $partialReconcile->company_id,
                'Debit MoveLine'
            );

            static::assertRelatedBelongsToCompany($partialReconcile->credit_move_id, MoveLine::class, 'Credit MoveLine', $partialReconcile->company_id);

            static::assertRelatedBelongsToCompany($partialReconcile->exchange_move_id, Move::class, 'Exchange Move', $partialReconcile->company_id);
        });
    }

    protected static function newFactory(): Factory
    {
        return PartialReconcileFactory::new();
    }
}
