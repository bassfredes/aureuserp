<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
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
//
// $ownerId is the fixture's intended owner and is only meaningful when
// called while that exact user is the authenticated actor — TableView's own
// creating() hook now forces user_id = Auth::id() regardless of what's
// passed here (#138 PR4 ola4A round 2 review), so every call site
// authenticates as $ownerId's user immediately before calling this.
function makeTableView(int $ownerId, bool $isPublic = false, ?string $filterableType = null): TableView
{
    return TableView::create([
        'name'            => 'fixture view',
        'user_id'         => $ownerId,
        'is_public'       => $isPublic,
        'filterable_type' => $filterableType ?? FILTERABLE_TYPE,
    ]);
}

// usuario A edita/elimina vista A → permitido
it('resolves a user\'s own private view for edit/delete/replace', function () {
    $actorA = SecurityHelper::authenticateWithPermissions([]);
    $viewA = makeTableView($actorA->id, isPublic: false);

    $resolved = TableView::resolveOwnedTableViewOrFail($viewA->id, FILTERABLE_TYPE);

    expect($resolved->is($viewA))->toBeTrue();
});

it('resolves a user\'s own public view for edit/delete/replace', function () {
    $actorA = SecurityHelper::authenticateWithPermissions([]);
    $viewA = makeTableView($actorA->id, isPublic: true);

    $resolved = TableView::resolveOwnedTableViewOrFail($viewA->id, FILTERABLE_TYPE);

    expect($resolved->is($viewA))->toBeTrue();
});

// usuario A edita/elimina vista B pública → rechazado
it('refuses to resolve another user\'s public view for edit/delete/replace', function () {
    $actorB = SecurityHelper::authenticateWithPermissions([]);
    $viewB = makeTableView($actorB->id, isPublic: true);

    SecurityHelper::authenticateWithPermissions([]);

    expect(fn () => TableView::resolveOwnedTableViewOrFail($viewB->id, FILTERABLE_TYPE))
        ->toThrow(NotFoundHttpException::class);

    expect(TableView::query()->whereKey($viewB->id)->exists())->toBeTrue();
});

// usuario A resuelve vista B privada → rechazado
it('refuses to resolve another user\'s private view for edit/delete/replace', function () {
    $actorB = SecurityHelper::authenticateWithPermissions([]);
    $viewB = makeTableView($actorB->id, isPublic: false);

    SecurityHelper::authenticateWithPermissions([]);

    expect(fn () => TableView::resolveOwnedTableViewOrFail($viewB->id, FILTERABLE_TYPE))
        ->toThrow(NotFoundHttpException::class);
});

// usuario A lee vista B pública → permitido
it('lets a user read (list) another user\'s public view', function () {
    $actorB = SecurityHelper::authenticateWithPermissions([]);
    $viewB = makeTableView($actorB->id, isPublic: true);

    SecurityHelper::authenticateWithPermissions([]);

    TableView::assertVisibleOrFail($viewB->id, FILTERABLE_TYPE);
})->throwsNoExceptions();

it('refuses to let a user read another user\'s private view', function () {
    $actorB = SecurityHelper::authenticateWithPermissions([]);
    $viewB = makeTableView($actorB->id, isPublic: false);

    SecurityHelper::authenticateWithPermissions([]);

    expect(fn () => TableView::assertVisibleOrFail($viewB->id, FILTERABLE_TYPE))
        ->toThrow(NotFoundHttpException::class);
});

// filterable_type distinto → rechazado
it('refuses to resolve an own view under a mismatched filterable_type', function () {
    $actorA = SecurityHelper::authenticateWithPermissions([]);
    $viewA = makeTableView($actorA->id, isPublic: false);

    expect(fn () => TableView::resolveOwnedTableViewOrFail($viewA->id, 'Some\\Other\\Type'))
        ->toThrow(NotFoundHttpException::class);
});

// no authenticated actor → fail closed. Built via a raw insert (bypassing
// Eloquent and its creating() hook, which now requires an authenticated
// actor) since no user is ever logged in during this test.
it('fails closed when resolving a TableView with no authenticated user', function () {
    $id = DB::table('table_views')->insertGetId([
        'name'            => 'fixture view',
        'user_id'         => 1,
        'is_public'       => true,
        'filterable_type' => FILTERABLE_TYPE,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    expect(fn () => TableView::resolveOwnedTableViewOrFail($id, FILTERABLE_TYPE))
        ->toThrow(AuthorizationException::class);
});

// favorite A sobre vista pública B → permitido
it('lets a user favorite another user\'s public saved view', function () {
    $actorB = SecurityHelper::authenticateWithPermissions([]);
    $viewB = makeTableView($actorB->id, isPublic: true);

    $actorA = SecurityHelper::authenticateWithPermissions([]);

    $favorite = TableViewFavorite::toggleForOwnViewOrFail('saved', $viewB->id, FILTERABLE_TYPE, true);

    expect($favorite->user_id)->toBe($actorA->id)
        ->and($favorite->is_favorite)->toBeTrue();
});

// favorite A sobre vista privada B → rechazado
it('refuses to let a user favorite another user\'s private saved view', function () {
    $actorB = SecurityHelper::authenticateWithPermissions([]);
    $viewB = makeTableView($actorB->id, isPublic: false);

    $actorA = SecurityHelper::authenticateWithPermissions([]);

    expect(fn () => TableViewFavorite::toggleForOwnViewOrFail('saved', $viewB->id, FILTERABLE_TYPE, true))
        ->toThrow(NotFoundHttpException::class);

    expect(TableViewFavorite::query()->where('view_key', $viewB->id)->where('user_id', $actorA->id)->exists())->toBeFalse();
});

// A elimina favorite de B → rechazado (toggle is always scoped to the caller's own user_id — B's row is untouched)
it('never lets a user remove another user\'s favorite', function () {
    $actorB = SecurityHelper::authenticateWithPermissions([]);
    $viewB = makeTableView($actorB->id, isPublic: true);
    $favoriteB = TableViewFavorite::create([
        'user_id'         => $actorB->id,
        'view_type'       => 'saved',
        'view_key'        => (string) $viewB->id,
        'filterable_type' => FILTERABLE_TYPE,
        'is_favorite'     => true,
    ]);

    SecurityHelper::authenticateWithPermissions([]);

    TableViewFavorite::toggleForOwnViewOrFail('saved', $viewB->id, FILTERABLE_TYPE, false);

    expect(TableViewFavorite::query()->whereKey($favoriteB->id)->first()->is_favorite)->toBeTruthy();
});

// Regresión: user_id nunca puede ser suplantado, ni pasándolo explícito al
// crear (el modelo lo sobreescribe con Auth::id()) ni intentando cambiarlo
// en un update (inmutable) — #138 PR4 ola4A round 2 review: el resolver
// público aceptaba antes un $userId de la request y lo confiaba como dueño.
it('forces user_id to the authenticated actor even when a different user_id is explicitly passed on create', function () {
    $actorA = SecurityHelper::authenticateWithPermissions([]);
    $actorB = SecurityHelper::authenticateWithPermissions([]);

    $favorite = TableViewFavorite::create([
        'user_id'         => $actorA->id,
        'view_type'       => 'kanban',
        'view_key'        => 'default',
        'filterable_type' => FILTERABLE_TYPE,
        'is_favorite'     => true,
    ]);

    expect($favorite->user_id)->toBe($actorB->id)
        ->and($favorite->user_id)->not->toBe($actorA->id);
});

it('refuses to change a TableViewFavorite\'s user_id on update, even for its own owner', function () {
    $actorA = SecurityHelper::authenticateWithPermissions([]);
    $actorB = SecurityHelper::authenticateWithPermissions([]);

    $favoriteB = TableViewFavorite::create([
        'view_type'       => 'kanban',
        'view_key'        => 'default',
        'filterable_type' => FILTERABLE_TYPE,
        'is_favorite'     => true,
    ]);

    expect(fn () => $favoriteB->update(['user_id' => $actorA->id]))
        ->toThrow(AuthorizationException::class);

    expect(TableViewFavorite::query()->whereKey($favoriteB->id)->first()->user_id)->toBe($actorB->id);
});

it('refuses to change a TableView\'s user_id on update, even for its own owner', function () {
    $actorA = SecurityHelper::authenticateWithPermissions([]);
    $actorB = SecurityHelper::authenticateWithPermissions([]);
    $viewB = makeTableView($actorB->id, isPublic: false);

    expect(fn () => $viewB->update(['user_id' => $actorA->id]))
        ->toThrow(AuthorizationException::class);

    expect(TableView::query()->whereKey($viewB->id)->first()->user_id)->toBe($actorB->id);
});
