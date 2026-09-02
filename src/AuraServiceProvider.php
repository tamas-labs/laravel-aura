<?php

declare(strict_types=1);

namespace TamasLabs\Aura;

use Illuminate\Support\ServiceProvider;
use TamasLabs\Aura\Console\AuraErrorsCommand;
use TamasLabs\Aura\Console\AuraTableMakeCommand;
use TamasLabs\Aura\Errors\DatabaseErrorStore;
use TamasLabs\Aura\Errors\ErrorIngestConfig;
use TamasLabs\Aura\Errors\ErrorStore;
use TamasLabs\Aura\Errors\LogErrorStore;

/**
 * Registers the package's configuration, its Artisan commands and — only when
 * it is switched on — the error ingest route.
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

        $this->app->bind(ErrorStore::class, function (): ErrorStore {
            $config = ErrorIngestConfig::fromConfig();

            return $config->usesDatabase()
                ? new DatabaseErrorStore($config)
                : new LogErrorStore($config);
        });
    }

    /**
     * Expose the config file and the error table's migration to
     * `php artisan vendor:publish`, register the Artisan commands, and load the
     * ingest route when it is switched on.
     *
     * @internal
     */
    public function boot(): void
    {
        if (ErrorIngestConfig::fromConfig()->enabled) {
            $this->loadRoutesFrom(__DIR__.'/../routes/aura-errors.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                $this->configPath() => $this->app->configPath('aura.php'),
            ], 'aura-config');

            $this->publishes([
                __DIR__.'/../database/migrations/create_aura_errors_table.php.stub' => $this->migrationPath(),
            ], 'aura-error-migrations');

            $this->commands([AuraTableMakeCommand::class, AuraErrorsCommand::class]);
        }
    }

    /**
     * Absolute path of the packaged config file.
     */
    private function configPath(): string
    {
        return __DIR__.'/../config/aura.php';
    }

    /**
     * Where the error table's migration is published to.
     *
     * Published rather than loaded: a library that generates JSON and a library
     * that creates a table on your database are different promises, and the
     * second one should be asked for. It is only needed by the `database`
     * driver, which is not the default.
     */
    private function migrationPath(): string
    {
        return $this->app->databasePath(
            'migrations/'.date('Y_m_d_His').'_create_aura_errors_table.php'
        );
    }
}
