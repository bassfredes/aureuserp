<?php

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Webkul\TableViews\Models\TableView;
use Webkul\TableViews\Models\TableViewFavorite;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';

const FILTERABLE_TYPE = 'App\\Filament\\Resources\\FixtureResource\\Pages\\ListFixtures';

beforeEach(function () {
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// Webkul\TableView\Database\Factories\TableViewFactory (singular "TableView")
// is not PSR-4 autoloadable under this plugin's composer.json (which only
// maps the plural "Webkul\TableViews\..." namespace) — a pre-existing,
// unrelated mismatch. Build rows directly instead of depending on it.
function makeTableView(int $userId, bool $isPublic = false, ?string $filterableType = null): TableView
{
    return TableView::create([
        'name'            => 'fixture view',
        'user_id'         => $userId,
        'is_public'       => $isPublic,
        'filterable_type' => $filterableType ?? FILTERABLE_TYPE,
    ]);
}

// usuario A edita/elimina vista A → permitido
it('resolves a user\'s own private view for edit/delete/replace', function () {
    $actorA = SecurityHelper::authenticateWithPermissions([]);
    $viewA  = makeTableView($actorA->id, isPublic: false);

    $resolved = TableView::resolveOwnedTableViewOrFail($viewA->id, FILTERABLE_TYPE, $actorA->id);

    expect($resolved->is($viewA))->toBeTrue();
});

it('resolves a user\'s own public view for edit/delete/replace', function () {
    $actorA = SecurityHelper::authenticateWithPermissions([]);
    $viewA  = makeTableView($actorA->id, isPublic: true);

    $resolved = TableView::resolveOwnedTableViewOrFail($viewA->id, FILTERABLE_TYPE, $actorA->id);

    expect($resolved->is($viewA))->toBeTrue();
});

// usuario A edita/elimina vista B pública → rechazado
it('refuses to resolve another user\'s public view for edit/delete/replace', function () {
    $actorB = SecurityHelper::authenticateWithPermissions([]);
    $viewB  = makeTableView($actorB->id, isPublic: true);

    $actorA = SecurityHelper::authenticateWithPermissions([]);

    expect(fn () => TableView::resolveOwnedTableViewOrFail($viewB->id, FILTERABLE_TYPE, $actorA->id))
        ->toThrow(NotFoundHttpException::class);

    expect(TableView::query()->whereKey($viewB->id)->exists())->toBeTrue();
});

// usuario A resuelve vista B privada → rechazado
it('refuses to resolve another user\'s private view for edit/delete/replace', function () {
    $actorB = SecurityHelper::authenticateWithPermissions([]);
    $viewB  = makeTableView($actorB->id, isPublic: false);

    $actorA = SecurityHelper::authenticateWithPermissions([]);

    expect(fn () => TableView::resolveOwnedTableViewOrFail($viewB->id, FILTERABLE_TYPE, $actorA->id))
        ->toThrow(NotFoundHttpException::class);
});

// usuario A lee vista B pública → permitido
it('lets a user read (list) another user\'s public view', function () {
    $actorB = SecurityHelper::authenticateWithPermissions([]);
    $viewB  = makeTableView($actorB->id, isPublic: true);

    $actorA = SecurityHelper::authenticateWithPermissions([]);

    TableView::assertVisibleOrFail($viewB->id, FILTERABLE_TYPE, $actorA->id);
})->throwsNoExceptions();

it('refuses to let a user read another user\'s private view', function () {
    $actorB = SecurityHelper::authenticateWithPermissions([]);
    $viewB  = makeTableView($actorB->id, isPublic: false);

    $actorA = SecurityHelper::authenticateWithPermissions([]);

    expect(fn () => TableView::assertVisibleOrFail($viewB->id, FILTERABLE_TYPE, $actorA->id))
        ->toThrow(NotFoundHttpException::class);
});

// filterable_type distinto → rechazado
it('refuses to resolve an own view under a mismatched filterable_type', function () {
    $actorA = SecurityHelper::authenticateWithPermissions([]);
    $viewA  = makeTableView($actorA->id, isPublic: false);

    expect(fn () => TableView::resolveOwnedTableViewOrFail($viewA->id, 'Some\\Other\\Type', $actorA->id))
        ->toThrow(NotFoundHttpException::class);
});

// favorite A sobre vista pública B → permitido
it('lets a user favorite another user\'s public saved view', function () {
    $actorB = SecurityHelper::authenticateWithPermissions([]);
    $viewB  = makeTableView($actorB->id, isPublic: true);

    $actorA = SecurityHelper::authenticateWithPermissions([]);

    $favorite = TableViewFavorite::toggleForOwnViewOrFail('saved', $viewB->id, FILTERABLE_TYPE, $actorA->id, true);

    expect($favorite->user_id)->toBe($actorA->id)
        ->and($favorite->is_favorite)->toBeTrue();
});

// favorite A sobre vista privada B → rechazado
it('refuses to let a user favorite another user\'s private saved view', function () {
    $actorB = SecurityHelper::authenticateWithPermissions([]);
    $viewB  = makeTableView($actorB->id, isPublic: false);

    $actorA = SecurityHelper::authenticateWithPermissions([]);

    expect(fn () => TableViewFavorite::toggleForOwnViewOrFail('saved', $viewB->id, FILTERABLE_TYPE, $actorA->id, true))
        ->toThrow(NotFoundHttpException::class);

    expect(TableViewFavorite::query()->where('view_key', $viewB->id)->where('user_id', $actorA->id)->exists())->toBeFalse();
});

// A elimina favorite de B → rechazado (toggle is always scoped to the caller's own user_id — B's row is untouched)
it('never lets a user remove another user\'s favorite', function () {
    $actorB     = SecurityHelper::authenticateWithPermissions([]);
    $viewB      = makeTableView($actorB->id, isPublic: true);
    $favoriteB  = TableViewFavorite::create([
        'user_id'         => $actorB->id,
        'view_type'       => 'saved',
        'view_key'        => (string) $viewB->id,
        'filterable_type' => FILTERABLE_TYPE,
        'is_favorite'     => true,
    ]);

    $actorA = SecurityHelper::authenticateWithPermissions([]);

    TableViewFavorite::toggleForOwnViewOrFail('saved', $viewB->id, FILTERABLE_TYPE, $actorA->id, false);

    expect(TableViewFavorite::query()->whereKey($favoriteB->id)->first()->is_favorite)->toBeTruthy();
});

it('never trusts a user_id supplied outside the acting user for favoriting', function () {
    $actorA = SecurityHelper::authenticateWithPermissions([]);
    $viewA  = makeTableView($actorA->id, isPublic: false);

    $actorB = SecurityHelper::authenticateWithPermissions([]);

    $favorite = TableViewFavorite::toggleForOwnViewOrFail('saved', $viewA->id, FILTERABLE_TYPE, $actorA->id, true);

    expect($favorite->user_id)->toBe($actorA->id)
        ->and($favorite->user_id)->not->toBe($actorB->id);
});
