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
use TamasLabs\Aura\Support\Messages;

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

        // A singleton because the section is read in four places on the way to
        // one answer — this binding, `boot()`, the route file and the
        // controller — and nothing may make them disagree. Rebinding it here is
        // also what keeps a test honest: `register()` running again drops the
        // resolved instance, so a provider re-registered against new config
        // hands out the new config.
        $this->app->singleton(
            ErrorIngestConfig::class,
            static fn (): ErrorIngestConfig => ErrorIngestConfig::fromConfig(),
        );

        $this->app->bind(ErrorStore::class, function (): ErrorStore {
            $config = $this->app->make(ErrorIngestConfig::class);

            return $config->usesDatabase()
                ? new DatabaseErrorStore($config)
                : new LogErrorStore($config);
        });
    }

    /**
     * Expose the config file, the translations and the error table's migration
     * to `php artisan vendor:publish`, register the Artisan commands, and load
     * the ingest route when it is switched on.
     *
     * @internal
     */
    public function boot(): void
    {
        // Loaded, not published: the messages have to resolve out of the box,
        // and publishing them is how a host *overrides* one, not how it gets
        // them at all.
        $this->loadTranslationsFrom($this->langPath(), Messages::NAMESPACE);

        if ($this->app->make(ErrorIngestConfig::class)->enabled) {
            $this->loadRoutesFrom(__DIR__.'/../routes/aura-errors.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                $this->configPath() => $this->app->configPath('aura.php'),
            ], 'aura-config');

            $this->publishes([
                $this->langPath() => $this->app->langPath('vendor/'.Messages::NAMESPACE),
            ], 'aura-lang');

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
     * Absolute path of the packaged translations.
     */
    private function langPath(): string
    {
        return __DIR__.'/../lang';
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
