<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;
use Webkul\Support\Models\Company;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // HasCompanyScope (ADR 0007) hides company-scoped records from any
        // authenticated user with no allowed companies. Feature tests across
        // plugins routinely authenticate a user first and then create a
        // Company for their fixtures — without this, every such fixture is
        // invisible to that same user's own requests (404s, null relations)
        // even though the test never meant to exercise cross-company
        // isolation. Grant access automatically instead of patching every
        // test file individually; tests that specifically assert isolation
        // still control company membership explicitly and are unaffected.
        Company::created(function (Company $company): void {
            if ($user = Auth::user()) {
                $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

                if (! $user->default_company_id) {
                    $user->forceFill(['default_company_id' => $company->id])->saveQuietly();
                }
            }
        });
    }
}
