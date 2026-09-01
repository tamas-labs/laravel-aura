<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the two things the demo needs that Testbench's skeleton does not have.
 *
 * Testbench boots an application with an **empty** middleware stack and no
 * middleware groups at all — it is built for tests, which call the router
 * directly. A browser does neither.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Globally, not on the route: a CORS preflight is an `OPTIONS` request
        // to a path that has no `OPTIONS` route, so route middleware would
        // never run. `HandleCors` answers it before the router is reached.
        $this->app->make(HttpKernel::class)->pushMiddleware(HandleCors::class);

        // `workbench.discovers.api` wraps `workbench/routes/api.php` in a
        // middleware group named `api`, which nothing here defines — an
        // unregistered group name is resolved as a class name and fails at
        // request time, not at boot.
        $this->app->make(Router::class)->middlewareGroup('api', [SubstituteBindings::class]);
    }
}
