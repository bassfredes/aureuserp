<?php

namespace Webkul\Account\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Database\Factories\AccountFactory;
use Webkul\Account\Enums\AccountType;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class Account extends Model
{
    use HasFactory;

    protected $table = 'accounts_accounts';

    protected $fillable = [
        'currency_id',
        'creator_id',
        'parent_id',
        'account_type',
        'name',
        'code',
        'note',
        'deprecated',
        'reconcile',
        'non_trade',
    ];

    protected $casts = [
        'deprecated'   => 'boolean',
        'reconcile'    => 'boolean',
        'non_trade'    => 'boolean',
        'account_type' => AccountType::class,
    ];

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    public function getDescendantIds(): array
    {
        $ids = [];

        foreach ($this->children as $child) {
            $ids = [
                ...$ids,
                $child->id,
                ...$child->getDescendantIds(),
            ];
        }

        return $ids;
    }

    public function taxes(): BelongsToMany
    {
        return $this->belongsToMany(Tax::class, 'accounts_account_taxes', 'account_id', 'tax_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'accounts_account_account_tags', 'account_id', 'account_tag_id');
    }

    public function journals(): BelongsToMany
    {
        return $this->belongsToMany(Journal::class, 'accounts_account_journals', 'account_id', 'journal_id');
    }

    public function moveLines(): HasMany
    {
        return $this->hasMany(MoveLine::class, 'account_id');
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'accounts_account_companies', 'account_id', 'company_id');
    }

    /**
     * Account has no company_id column of its own — its visibility is
     * many-to-many via accounts_account_companies, not strict_company like
     * the rest of this rollout. A financial row of company A may only
     * reference an Account whose pivot explicitly includes A (#138 review,
     * 2026-07-18). A null $accountId is a no-op (required-field validation
     * is the caller's own concern); a null $companyId fails closed, since
     * "no company to check against" can never justify skipping the check.
     */
    public static function assertEnabledForCompany(?int $accountId, ?int $companyId, string $label = 'Account'): void
    {
        if ($accountId === null) {
            return;
        }

        if ($companyId === null) {
            throw new AuthorizationException("The related {$label} could not be validated: no company was resolved to check it against.");
        }

        $enabled = static::query()
            ->whereKey($accountId)
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId))
            ->exists();

        if (! $enabled) {
            throw new AuthorizationException("The related {$label} is not enabled for this company.");
        }
    }

    /**
     * Idempotent counterpart to assertEnabledForCompany() for the one
     * legitimately different case: a Journal's own default/suspense/
     * profit/loss account isn't a transaction referencing a pre-existing,
     * separately-authorized Account (like MoveLine.account_id or
     * FiscalPositionAccount's mapping) — designating an Account as *this
     * journal's own* account is itself the act that enables it for the
     * journal's company, not a precondition to reject on (#138 review,
     * 2026-07-18). A null $accountId or $companyId is a no-op.
     */
    public static function ensureEnabledForCompany(?int $accountId, ?int $companyId): void
    {
        if ($accountId === null || $companyId === null) {
            return;
        }

        $account = static::query()->find($accountId);

        if ($account && ! $account->companies()->where('companies.id', $companyId)->exists()) {
            $account->companies()->attach($companyId);
        }
    }

    public static function getMostFrequentAccountsForPartner(
        int $companyId,
        int $partnerId,
        string $moveType,
        bool $filterNeverUsedAccounts = false,
        ?int $limit = null
    ) {
        $minDate = now()->subYears(2)->toDateString();

        $group = null;

        if (in_array($moveType, (new Move)->getInboundTypes(true))) {
            $group = 'income';
        } elseif (in_array($moveType, (new Move)->getOutboundTypes(true))) {
            $group = 'expense';
        }

        $query = DB::table('accounts_account_move_lines')
            ->select('accounts_account_move_lines.account_id')
            ->join('accounts_accounts', 'accounts_accounts.id', '=', 'accounts_account_move_lines.account_id')
            ->where('accounts_account_move_lines.company_id', $companyId)
            ->where('accounts_account_move_lines.partner_id', $partnerId)
            ->where('accounts_accounts.deprecated', false)
            ->whereDate('accounts_account_move_lines.date', '>=', $minDate);

        if ($group) {
            $query->where('accounts_accounts.internal_group', $group);
        }

        if (! $filterNeverUsedAccounts) {
            $accountsBase = DB::table('accounts_accounts')
                ->select('accounts_accounts.id as account_id')
                ->leftJoin('accounts_account_move_lines', function ($j) use ($companyId, $partnerId, $minDate) {
                    $j->on('accounts_account_move_lines.account_id', '=', 'accounts_accounts.id')
                        ->where('accounts_account_move_lines.company_id', $companyId)
                        ->where('accounts_account_move_lines.partner_id', $partnerId)
                        ->whereDate('accounts_account_move_lines.date', '>=', $minDate);
                })
                // accounts_accounts has no company_id column of its own
                // (#138 review, 2026-07-18) — visibility is many-to-many
                // via accounts_account_companies instead.
                ->whereExists(function ($existsQuery) use ($companyId) {
                    $existsQuery->select(DB::raw(1))
                        ->from('accounts_account_companies')
                        ->whereColumn('accounts_account_companies.account_id', 'accounts_accounts.id')
                        ->where('accounts_account_companies.company_id', $companyId);
                })
                ->where('accounts_accounts.deprecated', false);

            if ($group) {
                $accountsBase->where('accounts_accounts.internal_group', $group);
            }

            $query = $query->unionAll($accountsBase);
        }

        $query = DB::table(DB::raw("({$query->toSql()}) as q"))
            ->mergeBindings($query)
            ->select('q.account_id')
            ->join('accounts_accounts', 'accounts_accounts.id', '=', 'q.account_id')
            ->groupBy('q.account_id')
            ->orderByRaw('COUNT(q.account_id) DESC')
            ->orderBy('accounts_accounts.code', 'DESC');

        if ($limit) {
            $query->limit($limit);
        }

        return $query->pluck('account_id')->toArray();
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($account) {
            $account->creator_id ??= Auth::id();
        });
    }

    protected static function newFactory()
    {
        return AccountFactory::new();
    }
}
