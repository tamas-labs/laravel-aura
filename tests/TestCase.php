<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use TamasLabs\Aura\AuraServiceProvider;

/**
 * Base case for the package suite: boots a minimal Laravel application through
 * Testbench with this package's provider registered.
 */
abstract class TestCase extends Orchestra
{
    /**
     * Providers Testbench loads into the test application.
     *
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [AuraServiceProvider::class];
    }

    /**
     * Keep the suite on in-memory SQLite — there is no database container.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
    }
}
