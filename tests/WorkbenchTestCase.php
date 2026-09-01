<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * The demo application, booted the way `testbench serve` boots it.
 *
 * Separate from {@see TestCase} because it is a different application: the
 * providers, the routes, the CORS configuration and the schema all come from
 * `testbench.yaml` and `workbench/`, not from this file. That is the point —
 * a demo nobody runs rots, and this is the part of running it that CI can do.
 */
abstract class WorkbenchTestCase extends Orchestra
{
    use RefreshDatabase;
    use WithWorkbench;
}
