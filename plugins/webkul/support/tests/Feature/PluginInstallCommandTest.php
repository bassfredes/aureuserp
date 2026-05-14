<?php

use Illuminate\Support\Facades\Artisan;
use Webkul\PluginManager\Models\Plugin;

require_once __DIR__.'/../Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensureERPInstalled();
});

it('skips reinstalling installed dependencies during nested plugin installs', function () {
    Artisan::call('products:install', ['--no-interaction' => true]);

    $products = Plugin::query()->where('name', 'products')->first();

    expect((bool) $products?->is_installed)->toBeTrue();

    Artisan::call('inventories:install', ['--no-interaction' => true]);

    $output = Artisan::output();
    $inventories = Plugin::query()->where('name', 'inventories')->firstOrFail();

    expect($output)
        ->not->toContain('🎉 Package products has been installed!')
        ->and($output)->toContain('🎉 Package inventories has been installed!')
        ->and($inventories->dependencies()->where('name', 'products')->exists())->toBeTrue();
});
