<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Product\Models\Product;
use Webkul\Support\Models\Company;
use Webkul\Support\Traits\ValidatesRelatedCompanyScope;

require_once __DIR__.'/../Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('products');
});

/**
 * Direct unit coverage of the shared trait itself (D5b, aureuserp#137
 * review round 2): the domain aggregates that use it (Order.company_id is
 * NOT NULL, for instance) can't always construct every edge case the
 * reviewer asked for — a null effective company, or a null related
 * company — so this proves the trait's own contract in isolation, with a
 * throwaway class exposing its protected methods.
 */
class RelatedCompanyScopeTestSubject
{
    use ValidatesRelatedCompanyScope;

    public static function assertRelated(?int $relatedId, string $relatedClass, string $label, ?int $companyId): void
    {
        static::assertRelatedBelongsToCompany($relatedId, $relatedClass, $label, $companyId);
    }

    public static function resolveEffective(?int $parentId, string $parentClass, ?int $childCompanyId, string $label): ?int
    {
        return static::resolveEffectiveCompanyId($parentId, $parentClass, $childCompanyId, $label);
    }
}

// ── assertRelatedBelongsToCompany: strict equality, always ─────────────────

it('does not throw when the related record\'s company matches the effective company', function () {
    $company = Company::factory()->create();
    $product = Product::factory()->create(['company_id' => $company->id]);

    RelatedCompanyScopeTestSubject::assertRelated($product->id, Product::class, 'product', $company->id);

    expect(true)->toBeTrue();
});

it('throws when the effective company is null but the related record has a company', function () {
    $company = Company::factory()->create();
    $product = Product::factory()->create(['company_id' => $company->id]);

    expect(fn () => RelatedCompanyScopeTestSubject::assertRelated($product->id, Product::class, 'product', null))
        ->toThrow(AuthorizationException::class);
});

it('throws when the related record has a null company but the effective company is not null', function () {
    $company = Company::factory()->create();
    $product = Product::factory()->create(['company_id' => null]);

    expect(fn () => RelatedCompanyScopeTestSubject::assertRelated($product->id, Product::class, 'product', $company->id))
        ->toThrow(AuthorizationException::class);
});

it('does not throw when both the effective company and the related record\'s company are null', function () {
    $product = Product::factory()->create(['company_id' => null]);

    RelatedCompanyScopeTestSubject::assertRelated($product->id, Product::class, 'product', null);

    expect(true)->toBeTrue();
});

it('still throws for a soft-deleted related record from a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $product = Product::factory()->create(['company_id' => $companyB->id]);
    $product->delete();

    expect(fn () => RelatedCompanyScopeTestSubject::assertRelated($product->id, Product::class, 'product', $companyA->id))
        ->toThrow(AuthorizationException::class);
});

it('is a no-op when the related id itself is null', function () {
    $company = Company::factory()->create();

    RelatedCompanyScopeTestSubject::assertRelated(null, Product::class, 'product', $company->id);

    expect(true)->toBeTrue();
});

// ── resolveEffectiveCompanyId: parent-anchored resolution ───────────────────

it('returns the child\'s own company_id when there is no parent id to resolve', function () {
    $company = Company::factory()->create();

    $result = RelatedCompanyScopeTestSubject::resolveEffective(null, Product::class, $company->id, 'parent');

    expect($result)->toBe($company->id);
});

it('derives the effective company from the parent when the child omits company_id', function () {
    $parentCompany = Company::factory()->create();
    $parent = Product::factory()->create(['company_id' => $parentCompany->id]);

    $result = RelatedCompanyScopeTestSubject::resolveEffective($parent->id, Product::class, null, 'parent');

    expect($result)->toBe($parentCompany->id);
});

it('throws when the child\'s explicit company_id conflicts with its parent\'s', function () {
    $parentCompany = Company::factory()->create();
    $mismatchedCompany = Company::factory()->create();
    $parent = Product::factory()->create(['company_id' => $parentCompany->id]);

    expect(fn () => RelatedCompanyScopeTestSubject::resolveEffective($parent->id, Product::class, $mismatchedCompany->id, 'parent'))
        ->toThrow(AuthorizationException::class);
});

it('falls back to the child\'s own company_id when the parent id does not resolve to a real record', function () {
    $company = Company::factory()->create();

    $result = RelatedCompanyScopeTestSubject::resolveEffective(999999, Product::class, $company->id, 'parent');

    expect($result)->toBe($company->id);
});
