<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Webkul\Security\Models\User;
use Webkul\Support\Enums\CompanyContextMode;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensureERPInstalled();
    Auth::logout();
});

it('has no active context by default', function () {
    expect(CompanyContext::current())->toBeNull();
});

it('runForCompany exposes the company mode and id only during the callback', function () {
    $company = Company::factory()->create();

    $seen = null;

    $result = CompanyContext::runForCompany($company->id, reason: 'test', caller: 'test', callback: function () use (&$seen) {
        $seen = CompanyContext::current();

        return 'done';
    });

    expect($result)->toBe('done')
        ->and($seen->mode)->toBe(CompanyContextMode::COMPANY)
        ->and($seen->companyId)->toBe($company->id)
        ->and(CompanyContext::current())->toBeNull();
});

it('runForCompany throws for a company that does not exist', function () {
    expect(fn () => CompanyContext::runForCompany(999999, reason: 'test', caller: 'test', callback: fn () => null))
        ->toThrow(LogicException::class);

    expect(CompanyContext::current())->toBeNull();
});

it('runForCompany throws for a soft-deleted company', function () {
    $company = Company::factory()->create();
    $company->delete();

    expect(fn () => CompanyContext::runForCompany($company->id, reason: 'test', caller: 'test', callback: fn () => null))
        ->toThrow(LogicException::class);
});

it('runForAllCompanies exposes the all_companies mode with no company id', function () {
    $seen = null;

    CompanyContext::runForAllCompanies(reason: 'test', caller: 'test', callback: function () use (&$seen) {
        $seen = CompanyContext::current();
    });

    expect($seen->mode)->toBe(CompanyContextMode::ALL_COMPANIES)
        ->and($seen->companyId)->toBeNull()
        ->and(CompanyContext::current())->toBeNull();
});

it('runForBootstrap exposes the bootstrap mode with no company id', function () {
    $seen = null;

    CompanyContext::runForBootstrap(reason: 'test', caller: 'test', callback: function () use (&$seen) {
        $seen = CompanyContext::current();
    });

    expect($seen->mode)->toBe(CompanyContextMode::BOOTSTRAP)
        ->and($seen->companyId)->toBeNull();
});

it('restores the previous (empty) state after the callback throws', function () {
    expect(fn () => CompanyContext::runForAllCompanies(reason: 'test', caller: 'test', callback: function () {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect(CompanyContext::current())->toBeNull();
});

it('refuses to open a context for an authenticated actor', function () {
    $user = User::withoutEvents(fn () => User::factory()->create());

    Auth::setUser($user);

    expect(fn () => CompanyContext::runForAllCompanies(reason: 'test', caller: 'test', callback: fn () => null))
        ->toThrow(LogicException::class);
});

it('requires a non-empty reason and caller', function () {
    expect(fn () => CompanyContext::runForAllCompanies(reason: '', caller: 'test', callback: fn () => null))
        ->toThrow(LogicException::class);

    expect(fn () => CompanyContext::runForAllCompanies(reason: 'test', caller: '', callback: fn () => null))
        ->toThrow(LogicException::class);
});

it('throws when opening a different context while one is already active', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    CompanyContext::runForCompany($companyA->id, reason: 'outer', caller: 'test', callback: function () use ($companyB) {
        expect(fn () => CompanyContext::runForCompany($companyB->id, reason: 'inner', caller: 'test', callback: fn () => null))
            ->toThrow(LogicException::class);
    });
});

it('throws when opening all_companies while a company context is already active', function () {
    $company = Company::factory()->create();

    CompanyContext::runForCompany($company->id, reason: 'outer', caller: 'test', callback: function () {
        expect(fn () => CompanyContext::runForAllCompanies(reason: 'inner', caller: 'test', callback: fn () => null))
            ->toThrow(LogicException::class);
    });
});

it('reuses the active context on a reentrant call for the exact same mode and company (nested bootstrap install)', function () {
    $depthSeen = [];

    CompanyContext::runForBootstrap(reason: 'outer', caller: 'test', callback: function () use (&$depthSeen) {
        $depthSeen[] = CompanyContext::current();

        // Simulates a plugin install command recursively installing a
        // dependency's own install command in the same process (PR 2B) —
        // the inner call must reuse the outer context, not throw.
        CompanyContext::runForBootstrap(reason: 'inner (dependency install)', caller: 'test', callback: function () use (&$depthSeen) {
            $depthSeen[] = CompanyContext::current();
        });

        expect(CompanyContext::current())->not->toBeNull();
    });

    expect(CompanyContext::current())->toBeNull()
        ->and($depthSeen[0]->mode)->toBe(CompanyContextMode::BOOTSTRAP)
        ->and($depthSeen[1]->mode)->toBe(CompanyContextMode::BOOTSTRAP);
});

it('requireCompanyId returns the company id only in company mode', function () {
    $company = Company::factory()->create();

    $seen = null;

    CompanyContext::runForCompany($company->id, reason: 'test', caller: 'test', callback: function () use (&$seen) {
        $seen = CompanyContext::requireCompanyId();
    });

    expect($seen)->toBe($company->id);
});

it('requireCompanyId throws outside any active context', function () {
    expect(fn () => CompanyContext::requireCompanyId())->toThrow(LogicException::class);
});

it('requireCompanyId throws in all_companies mode', function () {
    CompanyContext::runForAllCompanies(reason: 'test', caller: 'test', callback: function () {
        expect(fn () => CompanyContext::requireCompanyId())->toThrow(LogicException::class);
    });
});

it('requireCompanyId throws in bootstrap mode', function () {
    CompanyContext::runForBootstrap(reason: 'test', caller: 'test', callback: function () {
        expect(fn () => CompanyContext::requireCompanyId())->toThrow(LogicException::class);
    });
});

it('logs started/completed audit events at the level matching the mode', function () {
    // Log::spy() alone leaves Log::channel(...) resolving to null (a spy
    // has no real return value for an unconfigured method) — andReturnSelf()
    // keeps the ->channel(...)->info(...) chain on the same mock.
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn ($message, $context) => $message === 'company_context.started'
            && $context['mode'] === 'company'
            && $context['reason'] === 'audit test'
            && $context['caller'] === 'test-caller'
        );
    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn ($message, $context) => $message === 'company_context.completed' && isset($context['duration_ms']));

    $company = Company::factory()->create();

    CompanyContext::runForCompany($company->id, reason: 'audit test', caller: 'test-caller', callback: fn () => null);
});

it('logs all_companies and bootstrap events at warning level', function () {
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn ($message, $context) => $message === 'company_context.started' && $context['mode'] === 'all_companies');
    Log::shouldReceive('warning')->withArgs(fn ($message) => $message === 'company_context.completed');

    CompanyContext::runForAllCompanies(reason: 'test', caller: 'test', callback: fn () => null);
});

it('logs a failed audit event with the exception class when the callback throws', function () {
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')->withArgs(fn ($message) => $message === 'company_context.started');
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn ($message, $context) => $message === 'company_context.failed' && $context['exception_class'] === RuntimeException::class);

    try {
        CompanyContext::runForAllCompanies(reason: 'test', caller: 'test', callback: function () {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }
});
