<?php

declare(strict_types=1);

use TamasLabs\Aura\AuraContract;
use TamasLabs\Aura\AuraServiceProvider;

it('registers the service provider', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(AuraServiceProvider::class);
});

it('merges the package config so aura.* always resolves', function (): void {
    expect(config('aura.pagination.default'))->toBe(15)
        ->and(config('aura.pagination.max'))->toBe(100);
});

it('publishes the config under the aura-config tag', function (): void {
    expect(AuraServiceProvider::pathsToPublish(AuraServiceProvider::class, 'aura-config'))
        ->not->toBeEmpty();
});

it('targets contract version 1.0', function (): void {
    expect(AuraContract::VERSION)->toBe('1.0');
});
