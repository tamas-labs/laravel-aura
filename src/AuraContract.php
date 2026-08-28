<?php

declare(strict_types=1);

namespace TamasLabs\Aura;

/**
 * The wire contract this package speaks, as published in `tamas-labs/aura-schema`.
 *
 * Nothing is sent over the wire under this name today — the response schema sets
 * `additionalProperties: true`, so a version field can be added later without a
 * breaking change.
 *
 * The schema package is a dev dependency, so runtime code cannot read
 * `AuraSchema::VERSION` directly; this constant restates it, and
 * `ContractSchemaTest` fails the moment the two disagree.
 */
final class AuraContract
{
    /**
     * Version of the Aura JSON contract this package targets.
     */
    public const string VERSION = '1.0';
}
