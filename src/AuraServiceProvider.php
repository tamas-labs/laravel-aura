<?php

declare(strict_types=1);

namespace TamasLabs\Aura;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the package's configuration and, later, its Artisan commands.
 */
final class AuraServiceProvider extends ServiceProvider
{
    /**
     * Merge the package defaults so `config('aura.*')` always resolves, even
     * when the host application has not published the config file.
     */
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'aura');
    }

    /**
     * Expose the config file to `php artisan vendor:publish --tag=aura-config`.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                $this->configPath() => $this->app->configPath('aura.php'),
            ], 'aura-config');
        }
    }

    /**
     * Absolute path of the packaged config file.
     */
    private function configPath(): string
    {
        return __DIR__.'/../config/aura.php';
    }
}
