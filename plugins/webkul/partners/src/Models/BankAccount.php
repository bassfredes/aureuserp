<?php

namespace Webkul\Partner\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Webkul\Partner\Database\Factories\BankAccountFactory;
use Webkul\Partner\Models\Scopes\BankAccountCompanyMembershipScope;
use Webkul\Security\Models\User;
use Webkul\Support\Enums\CompanyContextMode;
use Webkul\Support\Models\Bank;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

/**
 * BankAccount stays a child of Partner (global_party_identity — cross-
 * company by design): it gets neither HasCompanyScope nor a strict
 * company_id. Isolation instead comes from an explicit membership pivot
 * (partners_bank_account_companies) — a BankAccount is usable by a given
 * Company only once enabled for it. Absence of a membership row is not an
 * oversight to paper over: it means inaccessible until explicit
 * remediation (#138 PR4 ola4B, approved contract).
 *
 * Read isolation (#138 PR4 ola4C): BankAccountCompanyMembershipScope
 * enforces that same membership pivot on every query, registered here on
 * the physical owner only — the three zero-schema subclasses
 * (Contact\BankAccount, Accounting\BankAccount, Invoice\BankAccount) inherit
 * it via late static binding, since none of them override boot().
 */
class BankAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'partners_bank_accounts';

    protected $fillable = [
        'account_number',
        'account_holder_name',
        'is_active',
        'can_send_money',
        'creator_id',
        'partner_id',
        'bank_id',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'can_send_money' => 'boolean',
    ];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function enabledCompanies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'partners_bank_account_companies', 'bank_account_id', 'company_id');
    }

    public function isEnabledForCompany(?int $companyId): bool
    {
        if ($companyId === null) {
            return false;
        }

        return $this->enabledCompanies()->where('companies.id', $companyId)->exists();
    }

    public function enableForCompany(int $companyId): void
    {
        $this->enabledCompanies()->syncWithoutDetaching([$companyId]);
    }

    /**
     * Mirrors Account::ensureEnabledForCompany() — for referencing
     * aggregates where designating this BankAccount as their OWN
     * operational bank account (e.g. Journal) is itself the act that
     * enables it, not a case that requires prior enablement. Distinct
     * from partner_bank_id references elsewhere (Payment/Move/
     * PaymentRegister), which validate against an ALREADY-enabled
     * membership instead — see ValidatesRelatedCompanyScope call sites.
     */
    public static function ensureEnabledForCompany(?int $bankAccountId, ?int $companyId): void
    {
        if ($bankAccountId === null || $companyId === null) {
            return;
        }

        $bankAccount = static::withoutGlobalScope(BankAccountCompanyMembershipScope::class)->find($bankAccountId);

        $bankAccount?->enableForCompany($companyId);
    }

    /**
     * Mirrors Account::assertEnabledForCompany() exactly — for referencing
     * aggregates that pick an ALREADY-vetted bank account
     * (Payment/Move/PaymentRegister.partner_bank_id, Employee.bank_account_id)
     * rather than designating their own (see ensureEnabledForCompany()
     * above for that other case): these validate against an existing
     * membership instead of granting one.
     */
    public static function assertEnabledForCompany(?int $bankAccountId, ?int $companyId, string $label = 'BankAccount'): void
    {
        if ($bankAccountId === null) {
            return;
        }

        if ($companyId === null) {
            throw new AuthorizationException("The related {$label} could not be validated: no company was resolved to check it against.");
        }

        $enabled = static::withTrashed()
            ->withoutGlobalScope(BankAccountCompanyMembershipScope::class)
            ->whereKey($bankAccountId)
            ->whereHas('enabledCompanies', fn ($query) => $query->where('companies.id', $companyId))
            ->exists();

        if (! $enabled) {
            throw new AuthorizationException("The related {$label} is not enabled for this company.");
        }
    }

    /**
     * Approved contract: "partner_bank_id debe pertenecer a partner_id" —
     * a bank account referenced alongside a partner must actually belong
     * to that same partner, not merely be enabled for the right company.
     */
    public static function assertBelongsToPartner(?int $bankAccountId, ?int $partnerId, string $label = 'BankAccount'): void
    {
        if ($bankAccountId === null || $partnerId === null) {
            return;
        }

        $bankAccount = static::withTrashed()->withoutGlobalScope(BankAccountCompanyMembershipScope::class)->find($bankAccountId);

        if ($bankAccount && (int) $bankAccount->partner_id !== (int) $partnerId) {
            throw new AuthorizationException("The related {$label} does not belong to the referenced partner.");
        }
    }

    /**
     * Explicit, audited bypass of BankAccountCompanyMembershipScope for
     * cross-company reporting. Restricted to super_admin; every call is
     * logged. Mirrors HasCompanyScope::forAllCompanies() (#138 PR4 ola4B),
     * reimplemented locally since this model does not use that trait (its
     * isolation column isn't company_id).
     */
    public static function forAllCompanies(): Builder
    {
        $user = Auth::user();

        abort_unless(static::actingUserIsSuperAdmin(), 403);

        Log::channel(config('logging.default'))->warning('cross-company query bypass', [
            'model'   => static::class,
            'user_id' => $user->id,
        ]);

        return static::withoutGlobalScope(BankAccountCompanyMembershipScope::class);
    }

    public static function actingUserIsSuperAdmin(): bool
    {
        $user = Auth::user();

        return (bool) $user?->roles->pluck('name')
            ->contains(fn ($name) => strtolower($name) === 'super_admin');
    }

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope(new BankAccountCompanyMembershipScope);

        static::creating(function ($bankAccount) {
            $bankAccount->creator_id ??= Auth::id();

            $bankAccount->account_holder_name = $bankAccount->partner->name;
        });

        static::updating(function ($bankAccount) {
            $bankAccount->account_holder_name = $bankAccount->partner->name;
        });

        // Creation from a company enables that membership (approved
        // contract) — an authenticated actor's own company, or an active
        // CompanyContext::COMPANY mode context. ALL_COMPANIES/bootstrap/no-
        // context creations enable nothing: an unused/ambiguous row must
        // stay inaccessible until explicit remediation, never guessed at.
        static::created(function (self $bankAccount) {
            $authUser = Auth::user();

            if ($authUser?->default_company_id !== null) {
                $bankAccount->enableForCompany((int) $authUser->default_company_id);

                return;
            }

            $context = CompanyContext::current();

            if ($context?->mode === CompanyContextMode::COMPANY) {
                $bankAccount->enableForCompany($context->companyId);
            }
        });
    }

    protected static function newFactory(): BankAccountFactory
    {
        return BankAccountFactory::new();
    }
}
