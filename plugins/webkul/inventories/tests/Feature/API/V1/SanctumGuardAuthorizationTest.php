<?php

// Regresion para el bug RBAC documentado en el comment de preflight de
// bassfredes/Intelligent-Integration-Suite#145: bajo auth:sanctum,
// Auth::shouldUse('sanctum') mutaba config('auth.defaults.guard') a
// "sanctum" para el resto del request, y como Webkul\Security\Models\User
// declaraba guard_name = ['web', 'sanctum'], Spatie\Permission\Guard::
// getDefaultName() resolvia el guard efectivo a "sanctum" -- pero todos los
// permisos reales estan sembrados unicamente bajo guard_name="web" (ver
// database/seeders/ShieldSeeder.php), asi que $user->can(...) siempre
// devolvia false para un usuario autenticado via token real, sin importar
// que permisos tuviera. Fix: Webkul\Security\Models\User::$guard_name a un
// unico valor "web".
//
// Deliberadamente NO usa SecurityHelper::authenticateWithPermissions() --
// antes de este fix, ese helper duplicaba permisos bajo web y sanctum, lo
// que enmascaraba la regresion que este test reproduce. Este test siembra
// el permiso solo bajo "web" (como lo hace ShieldSeeder en produccion) y
// autentica con un token Sanctum real via el header Authorization, para
// pasar por el mismo middleware auth:sanctum -> Auth::shouldUse('sanctum')
// que disparaba el bug.

use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\User;

require_once __DIR__.'/../../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('inventories');
});

function actingWithRealSanctumToken(array $webGuardPermissionNames = []): array
{
    $user = User::withoutEvents(fn (): User => User::factory()->create());

    $user->forceFill(['resource_permission' => PermissionType::GLOBAL])->saveQuietly();

    foreach ($webGuardPermissionNames as $name) {
        $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
    }

    $token = $user->createToken('sanctum-guard-regression-test')->plainTextToken;

    return [$user, $token];
}

it('authorizes a Sanctum-authenticated user whose permission is seeded only under guard "web"', function () {
    [$user, $token] = actingWithRealSanctumToken(['view_any_inventory_warehouse']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('admin.api.v1.inventories.warehouses.index'))
        ->assertOk()
        ->assertJsonStructure(['data', 'meta', 'links']);
});

it('forbids a Sanctum-authenticated user with no permission', function () {
    [$user, $token] = actingWithRealSanctumToken([]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('admin.api.v1.inventories.warehouses.index'))
        ->assertForbidden();
});
