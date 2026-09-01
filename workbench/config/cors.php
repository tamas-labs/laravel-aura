<?php

declare(strict_types=1);

/**
 * Replaces Testbench's own `config/cors.php` (`workbench.discovers.config`).
 *
 * Wide open on purpose, and only ever loaded by `testbench serve`: this
 * application exists so a Vite dev server on another port can call it. Nothing
 * here is published, and nothing here is what a host application should copy —
 * a real deployment names its origins.
 */
return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Vite's default, and whatever else is being pointed at this. Aura sends no
    // cookies, so `supports_credentials` stays false and `*` stays legal.
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
