<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use TamasLabs\Aura\AuraContract;
use TamasLabs\Aura\AuraServiceProvider;

it('registers the service provider', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(AuraServiceProvider::class);
});

it('merges the package config so aura.* always resolves', function (): void {
    expect(config('aura.pagination.max'))->toBe(100);
});

it('publishes the config under the aura-config tag', function (): void {
    expect(AuraServiceProvider::pathsToPublish(AuraServiceProvider::class, 'aura-config'))
        ->not->toBeEmpty();
});

it('publishes the error table migration under its own tag', function (): void {
    // Published rather than loaded: a library that generates JSON and one that
    // creates a table on your database are different promises.
    $paths = AuraServiceProvider::pathsToPublish(AuraServiceProvider::class, 'aura-error-migrations');

    expect($paths)->not->toBeEmpty()
        ->and(array_key_first($paths))->toEndWith('create_aura_errors_table.php.stub')
        ->and(implode('', array_map(fn (mixed $path): string => (string) json_encode($path), $paths)))
        ->toContain('_create_aura_errors_table.php');
});

it('leaves the error ingest off in the packaged config', function (): void {
    expect(config('aura.errors.enabled'))->toBeFalse()
        ->and(config('aura.errors.driver'))->toBe('log');
});

it('registers both Artisan commands', function (): void {
    expect(array_keys(Artisan::all()))
        ->toContain('make:aura-table')
        ->toContain('aura:errors');
});

it('targets contract version 1.0', function (): void {
    expect(AuraContract::VERSION)->toBe('1.0');
});
