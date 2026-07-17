<?php

namespace Webkul\Support\Services;

use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Throwable;
use Webkul\Support\Enums\CompanyContextMode;
use Webkul\Support\Models\Company;
use Webkul\Support\ValueObjects\CompanyContextState;

/**
 * Declares, for the duration of one callback, why a no-authenticated-user
 * process is allowed to see one company (`company`), all companies
 * (`all_companies`) or bypass isolation for install/seed (`bootstrap`) —
 * see ADR 0007 "Contextos de sistema sin actor autenticado". CompanyScope
 * reads current() to decide its filter; nothing else should read the
 * static state directly.
 *
 * Callback-only API by design: no enter()/leave() pair that a caller could
 * forget to balance. Exactly one context can be active at a time — this
 * first version has no privilege-escalation/de-escalation rules for
 * nested contexts, so opening a *different* one while one is active is a
 * bug, not a use case, and throws rather than silently stacking or
 * replacing.
 *
 * One narrow reentrancy exception: a call that requests the exact same
 * mode + company_id as the context already active reuses it instead of
 * throwing (tracked via $depth). This surfaced during PR 2B — plugin
 * install commands install their own dependencies by recursively calling
 * `$this->call($dependency.':install', ...)` in the same process, so a
 * dependency's own `runForBootstrap()` call happens while the parent
 * plugin's is still open. That is call-stack recursion of one bootstrap
 * operation, not a privilege change, so it reuses rather than rejecting.
 * Only the outermost call emits started/completed/failed audit events.
 */
class CompanyContext
{
    private static ?CompanyContextState $current = null;

    private static int $depth = 0;

    public static function current(): ?CompanyContextState
    {
        return static::$current;
    }

    /**
     * Only meaningful in `company` mode — `all_companies` and `bootstrap`
     * are visibility-only bypasses and carry no single effective company
     * to write with (ADR 0007, "Visibilidad vs. compañía efectiva de
     * escritura"). A caller needing a company to write under those modes
     * must be given one explicitly, never inferred from this context.
     */
    public static function requireCompanyId(): int
    {
        $state = static::$current;

        if (! $state || $state->mode !== CompanyContextMode::COMPANY || $state->companyId === null) {
            throw new LogicException('CompanyContext::requireCompanyId() called outside an active company-mode context.');
        }

        return $state->companyId;
    }

    public static function runForCompany(int $companyId, string $reason, string $caller, Closure $callback, ?string $correlationId = null): mixed
    {
        if (! Company::whereKey($companyId)->exists()) {
            throw new LogicException("CompanyContext::runForCompany() requires an existing company (id {$companyId}).");
        }

        return static::run(
            new CompanyContextState(CompanyContextMode::COMPANY, $companyId, $reason, $caller, $correlationId ?? (string) Str::uuid()),
            $callback,
        );
    }

    public static function runForAllCompanies(string $reason, string $caller, Closure $callback, ?string $correlationId = null): mixed
    {
        return static::run(
            new CompanyContextState(CompanyContextMode::ALL_COMPANIES, null, $reason, $caller, $correlationId ?? (string) Str::uuid()),
            $callback,
        );
    }

    /**
     * Console-only: install and initial-seed run under Artisan, never as
     * part of an HTTP request, so this is the one mode allowed to check
     * app()->runningInConsole() as a precondition rather than delegating
     * entirely to caller discipline.
     */
    public static function runForBootstrap(string $reason, string $caller, Closure $callback, ?string $correlationId = null): mixed
    {
        if (! app()->runningInConsole()) {
            throw new LogicException('CompanyContext::runForBootstrap() may only run in console (install/seed).');
        }

        return static::run(
            new CompanyContextState(CompanyContextMode::BOOTSTRAP, null, $reason, $caller, $correlationId ?? (string) Str::uuid()),
            $callback,
        );
    }

    private static function run(CompanyContextState $state, Closure $callback): mixed
    {
        if (Auth::user()) {
            throw new LogicException('CompanyContext cannot be opened for an authenticated actor — an authenticated user never needs a system context.');
        }

        if (trim($state->reason) === '' || trim($state->caller) === '') {
            throw new LogicException('CompanyContext requires a non-empty reason and caller.');
        }

        if (static::$current) {
            if (static::$current->mode !== $state->mode || static::$current->companyId !== $state->companyId) {
                throw new LogicException('CompanyContext is already active ('.static::$current->mode->value.') — nesting into a different context is not supported.');
            }

            static::$depth++;

            try {
                return $callback();
            } finally {
                static::$depth--;
            }
        }

        static::$current = $state;
        static::$depth = 1;

        $startedAt = microtime(true);

        try {
            static::audit('started', $state);

            $result = $callback();

            static::audit('completed', $state, durationMs: static::elapsedMs($startedAt));

            return $result;
        } catch (Throwable $e) {
            static::audit('failed', $state, durationMs: static::elapsedMs($startedAt), exception: $e);

            throw $e;
        } finally {
            static::$depth--;

            if (static::$depth === 0) {
                static::$current = null;
            }
        }
    }

    private static function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private static function audit(string $event, CompanyContextState $state, ?int $durationMs = null, ?Throwable $exception = null): void
    {
        $level = match ($state->mode) {
            CompanyContextMode::COMPANY                                      => 'info',
            CompanyContextMode::ALL_COMPANIES, CompanyContextMode::BOOTSTRAP => 'warning',
        };

        $context = [
            'mode'           => $state->mode->value,
            'company_id'     => $state->companyId,
            'reason'         => $state->reason,
            'caller'         => $state->caller,
            'correlation_id' => $state->correlationId,
        ];

        if ($durationMs !== null) {
            $context['duration_ms'] = $durationMs;
        }

        if ($exception) {
            $context['exception_class'] = $exception::class;
        }

        Log::channel(config('logging.default'))->{$level}("company_context.{$event}", $context);
    }
}
