<?php

namespace Webkul\Support\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Webkul\Support\Models\Scopes\CompanyScope;

/**
 * Applies automatic company_id isolation to a model. Unlike HasPermissionScope
 * (opt-in, called explicitly per Resource), this scope is registered
 * automatically for every model that uses the trait — company isolation is
 * default-on, bypassed only through the explicit, audited forAllCompanies().
 */
trait HasCompanyScope
{
    public static function bootHasCompanyScope(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    /**
     * Explicit, audited bypass of the company scope for cross-company
     * reporting. Restricted to super_admin; every call is logged.
     */
    public static function forAllCompanies(): Builder
    {
        $user = Auth::user();

        // Role::getNameAttribute() forces ucfirst() on the stored name, so
        // hasRole('super_admin') would miss it via exact string match — compare
        // case-insensitively instead of relying on Spatie's raw comparison.
        $isSuperAdmin = (bool) $user?->roles->pluck('name')
            ->contains(fn ($name) => strtolower($name) === 'super_admin');

        abort_unless($isSuperAdmin, 403);

        Log::channel(config('logging.default'))->warning('cross-company query bypass', [
            'model'   => static::class,
            'user_id' => $user->id,
        ]);

        return static::withoutGlobalScope(CompanyScope::class);
    }
}
