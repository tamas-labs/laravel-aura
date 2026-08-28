<?php

declare(strict_types=1);

use Illuminate\Http\Request;
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

/**
 * An HTTP request carrying an Aura payload the way the contract prescribes:
 * a JSON body for POST/PUT/PATCH, query parameters for GET/DELETE.
 *
 * @param  array<string, mixed>  $payload
 */
function auraHttpRequest(array $payload, string $method = 'POST'): Request
{
    if (in_array($method, ['GET', 'DELETE'], true)) {
        return Request::create('/api/users', $method, $payload);
    }

    return Request::create(
        '/api/users',
        $method,
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode($payload, JSON_THROW_ON_ERROR),
    );
}
