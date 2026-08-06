<?php

use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Models\CurrencyRate;

require_once __DIR__.'/../../../Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../Helpers/TestBootstrapHelper.php';

const CURRENCY_RATE_JSON_STRUCTURE = [
    'id',
    'name',
    'rate',
    'inverse_rate',
    'currency_id',
    'company_id',
    'creator_id',
    'created_at',
    'updated_at',
];

beforeEach(function () {
    TestBootstrapHelper::ensureERPInstalled();
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

function actingAsCurrencyRateApiUser(array $permissions): void
{
    SecurityHelper::authenticateWithPermissions($permissions);
}

function currencyRateRoute(string $action, mixed $currency, mixed $rate = null): string
{
    $name = "admin.api.v1.support.currencies.rates.{$action}";

    $parameters = ['currency' => $currency];

    if ($rate !== null) {
        $parameters['rate'] = $rate;
    }

    return route($name, $parameters);
}

it('requires authentication to list currency rates', function () {
    $currency = Currency::factory()->create();

    $this->getJson(currencyRateRoute('index', $currency))
        ->assertUnauthorized();
});

it('forbids listing currency rates without permission', function () {
    actingAsCurrencyRateApiUser([]);

    $currency = Currency::factory()->create();

    $this->getJson(currencyRateRoute('index', $currency))
        ->assertForbidden();
});

it('lists currency rates for authorized users', function () {
    actingAsCurrencyRateApiUser(['view_support_currency']);

    $currency = Currency::factory()->create();
    $otherCurrency = Currency::factory()->create();
    // No explicit company_id: the factory's own Company::factory() default
    // creates a fresh company per rate, auto-granted to the already
    // authenticated acting user by the global Company::created listener
    // (tests/TestCase.php) — company_id null is no longer a bare fixture
    // default under the new write guard (#138 PR4 A4D-0), only an
    // explicit, authorized choice.
    $firstRate = CurrencyRate::factory()->create(['currency_id' => $currency->id]);
    $secondRate = CurrencyRate::factory()->create(['currency_id' => $currency->id]);
    CurrencyRate::factory()->create(['currency_id' => $otherCurrency->id]);

    $response = $this->getJson(currencyRateRoute('index', $currency));

    $response
        ->assertOk()
        ->assertJsonStructure(['data' => ['*' => CURRENCY_RATE_JSON_STRUCTURE]]);

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toContain($firstRate->id, $secondRate->id);
});

it('creates a currency rate with valid payload', function () {
    actingAsCurrencyRateApiUser(['update_support_currency']);

    $currency = Currency::factory()->create();
    // A regular actor cannot submit company_id: null (shared row) — that is
    // now restricted to super_admin (#138 PR4 A4D-0). Company created after
    // the user is authenticated, so the global Company::created listener
    // (tests/TestCase.php) auto-grants it, matching this codebase's
    // established fixture convention.
    $company = Company::factory()->create();
    $payload = CurrencyRate::factory()->make([
        'currency_id' => $currency->id,
        'company_id'  => $company->id,
    ])->toArray();
    unset($payload['currency_id']);

    $this->postJson(currencyRateRoute('store', $currency), $payload)
        ->assertCreated()
        ->assertJsonPath('message', 'Currency rate created successfully.')
        ->assertJsonPath('data.rate', (string) $payload['rate']);

    $this->assertDatabaseHas('currency_rates', [
        'currency_id' => $currency->id,
        'name'        => $payload['name'],
    ]);
});

it('validates required fields when creating a currency rate', function (string $field) {
    actingAsCurrencyRateApiUser(['update_support_currency']);

    $currency = Currency::factory()->create();
    $payload = CurrencyRate::factory()->make([
        'currency_id' => $currency->id,
        'company_id'  => null,
    ])->toArray();
    unset($payload['currency_id'], $payload[$field]);

    $this->postJson(currencyRateRoute('store', $currency), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with(['name', 'rate']);

it('shows a currency rate for authorized users', function () {
    actingAsCurrencyRateApiUser(['view_support_currency']);

    $currency = Currency::factory()->create();
    $rate = CurrencyRate::factory()->create(['currency_id' => $currency->id]);

    $this->getJson(currencyRateRoute('show', $currency, $rate))
        ->assertOk()
        ->assertJsonPath('data.id', $rate->id)
        ->assertJsonStructure(['data' => CURRENCY_RATE_JSON_STRUCTURE]);
});

it('returns not found for a rate that does not belong to the currency', function () {
    actingAsCurrencyRateApiUser(['view_support_currency']);

    $currency = Currency::factory()->create();
    $otherCurrency = Currency::factory()->create();
    $rate = CurrencyRate::factory()->create(['currency_id' => $otherCurrency->id]);

    $this->getJson(currencyRateRoute('show', $currency, $rate))
        ->assertNotFound();
});

it('updates a currency rate for authorized users', function () {
    actingAsCurrencyRateApiUser(['update_support_currency']);

    $currency = Currency::factory()->create();
    $rate = CurrencyRate::factory()->create(['currency_id' => $currency->id]);

    $this->patchJson(currencyRateRoute('update', $currency, $rate), ['rate' => 1.25])
        ->assertOk()
        ->assertJsonPath('message', 'Currency rate updated successfully.')
        ->assertJsonPath('data.rate', '1.250000');

    $this->assertDatabaseHas('currency_rates', [
        'id'   => $rate->id,
        'rate' => 1.25,
    ]);
});

it('deletes a currency rate for authorized users', function () {
    actingAsCurrencyRateApiUser(['update_support_currency', 'delete_support_currency']);

    $currency = Currency::factory()->create();
    $rate = CurrencyRate::factory()->create(['currency_id' => $currency->id]);

    $this->deleteJson(currencyRateRoute('destroy', $currency, $rate))
        ->assertOk()
        ->assertJsonPath('message', 'Currency rate deleted successfully.');

    $this->assertDatabaseMissing('currency_rates', ['id' => $rate->id]);
});
