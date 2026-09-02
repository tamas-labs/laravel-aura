<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TamasLabs\Aura\Errors\ErrorIngestConfig;
use TamasLabs\Aura\Errors\ErrorIngestController;

/*
|--------------------------------------------------------------------------
| Aura error ingest
|--------------------------------------------------------------------------
|
| Loaded by AuraServiceProvider only while `aura.errors.enabled` is true, so a
| fresh install has no such route at all.
|
| The middleware comes from the config rather than from here, and the default
| does not include `web`: Aura reports with a native `fetch()` and sends no CSRF
| token, so the web group would answer 419 — and the client would then retry the
| same batch forever.
|
*/

$config = ErrorIngestConfig::fromConfig();

Route::post($config->path, ErrorIngestController::class)
    ->middleware($config->middleware)
    ->name('aura.errors.store');
