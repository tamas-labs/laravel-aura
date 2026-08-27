<?php

declare(strict_types=1);

namespace TamasLabs\Aura;

/**
 * The wire contract this package speaks, as published in `.claude/docs/schema/`.
 *
 * Nothing is sent over the wire under this name today — the response schema sets
 * `additionalProperties: true`, so a version field can be added later without a
 * breaking change. Until then this constant is where the version lives, and what
 * the contract tests assert against.
 */
final class AuraContract
{
    /**
     * Version of the Aura JSON contract this package targets.
     */
    public const string VERSION = '1.0';
}
