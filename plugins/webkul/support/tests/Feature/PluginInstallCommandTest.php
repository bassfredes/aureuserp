<?php

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Webkul\PluginManager\Models\Plugin;

require_once __DIR__.'/../Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensureERPInstalled();
});

it('skips reinstalling installed dependencies during nested plugin installs', function () {
    Artisan::call('products:install', ['--no-interaction' => true]);

    $products = Plugin::query()->where('name', 'products')->first();

    expect((bool) $products?->is_installed)->toBeTrue();

    // Artisan::output() reflects only the most recent Artisan::call()
    // process-wide. InstallCommand::handle() ends by calling
    // Package::refreshPluginCaches(), which itself calls
    // Artisan::call('optimize:clear') — clobbering Artisan::output() before
    // this test can read it. Pass our own buffer so we read exactly what
    // this command wrote, independent of that internal call.
    $output = new BufferedOutput;
    Artisan::call('inventories:install', ['--no-interaction' => true], $output);

    $inventories = Plugin::query()->where('name', 'inventories')->firstOrFail();

    expect($output->fetch())
        ->not->toContain('🎉 Package products has been installed!')
        ->toContain('🎉 Package inventories has been installed!')
        ->and($inventories->dependencies()->where('name', 'products')->exists())->toBeTrue();
});
