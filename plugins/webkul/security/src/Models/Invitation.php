<?php

namespace Webkul\Security\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Webkul\Security\Database\Factories\InvitationFactory;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;

/**
 * company_id is captured from the inviting actor's own authorized company
 * at issue time and carried through unchanged to the accepted User — see
 * AcceptInvitation::create(). Deliberately does NOT use HasCompanyScope:
 * the accept route is guest-accessible by design (signed URL, no
 * authenticated actor, see routes/web.php), and CompanyScope's global
 * scope fails closed with no user/context — enabling it here would 404
 * every legitimate invitee (#138 PR4 ola4B). The signed URL itself, plus
 * the not-accepted/not-expired checks in AcceptInvitation, are the accept
 * path's authorization — the same shape as the documented Partner/
 * customer-guard portal bypass (ADR 0007).
 *
 * Also deliberately does NOT use HasStrictCompanyId: that trait
 * re-authorizes company_id on EVERY save, including updates with no actor
 * at all — which is exactly what the guest accept flow's own
 * `accepted_at` update is. A bespoke boot() below authorizes company_id
 * at create time and on every AUTHENTICATED update (closing the same
 * "authenticated admin edits a foreign-company row" gap HasStrictCompanyId
 * exists for), while skipping that check when there is genuinely no actor
 * and no CompanyContext — the accept flow's signed URL + lockForUpdate +
 * state checks already are that write's authorization.
 */
class Invitation extends Model
{
    use HasFactory;

    protected $table = 'user_invitations';

    protected $fillable = [
        'email',
        'company_id',
        'role_id',
        'token',
        'invited_by',
        'expires_at',
        'accepted_at',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $invitation) {
            $authUser = Auth::user();

            $invitation->invited_by ??= $authUser?->id;
            $invitation->company_id ??= $authUser?->default_company_id;
            $invitation->token ??= (string) Str::uuid();
            $invitation->expires_at ??= now()->addDays(7);

            if ($invitation->company_id === null) {
                throw new AuthorizationException('Invitation requires a company_id and none could be resolved from the acting user.');
            }

            CompanyScope::assertCanWriteCompany((int) $invitation->company_id);
        });

        static::updating(function (self $invitation) {
            $originalCompanyId = $invitation->getOriginal('company_id');

            if ($originalCompanyId !== null && (int) $originalCompanyId !== (int) $invitation->company_id) {
                throw new AuthorizationException('Changing the company of this Invitation is forbidden — archive it and create a new one instead.');
            }

            if (Auth::check() || CompanyContext::current() !== null) {
                CompanyScope::assertCanWriteCompany((int) $originalCompanyId);
            }
        });
    }

    protected static function newFactory(): InvitationFactory
    {
        return InvitationFactory::new();
    }
}
