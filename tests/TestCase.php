<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use TamasLabs\Aura\AuraServiceProvider;
use TamasLabs\Aura\Query\AuraQuery;

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

    /**
     * The fixture schema the query tests run against.
     *
     * Declared here rather than as migration files: these tables exist only to
     * give {@see AuraQuery} something to constrain, and
     * nothing in the package ships them.
     */
    protected function defineDatabaseMigrations(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('tier')->nullable();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('status')->nullable();
            $table->decimal('balance', 12, 2);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('nickname');
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('title');
        });
    }
}
