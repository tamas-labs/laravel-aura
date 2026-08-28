<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use TamasLabs\Aura\Tests\Contract\ContractValidator;
use TamasLabs\Aura\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Assert that a payload is one Aura would accept as an API response.
 *
 * Plain functions rather than `expect()->extend()`: custom Pest expectations are
 * invisible to PHPStan, and this suite runs at level `max`.
 */
function assertMatchesAuraResponseSchema(mixed $payload): void
{
    $result = ContractValidator::response($payload);

    Assert::assertTrue(
        $result->valid,
        'Payload does not match the Aura response schema:'.$result->report()
    );
}

/**
 * Assert that a payload is one Aura would send as an API request.
 */
function assertMatchesAuraRequestSchema(mixed $payload): void
{
    $result = ContractValidator::request($payload);

    Assert::assertTrue(
        $result->valid,
        'Payload does not match the Aura request schema:'.$result->report()
    );
}

/**
 * Decode one of the JSON files shipped by the schema package.
 *
 * Decoded to objects, not arrays: JSON Schema distinguishes an empty object from
 * an empty array, and `json_decode(..., true)` erases that difference.
 */
function auraJsonFile(string $absolute): mixed
{
    $contents = file_get_contents($absolute);

    Assert::assertNotFalse($contents, "Cannot read {$absolute}");

    return json_decode((string) $contents, false, 512, JSON_THROW_ON_ERROR);
}
