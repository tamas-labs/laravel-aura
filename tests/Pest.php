<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use PHPUnit\Framework\Assert;
use TamasLabs\Aura\Cell\CellRules;
use TamasLabs\Aura\Table\AuraTable;
use TamasLabs\Aura\Table\Column;
use TamasLabs\Aura\Table\ColumnGroup;
use TamasLabs\Aura\Table\Footer;
use TamasLabs\Aura\Table\TableSettings;
use TamasLabs\Aura\Tests\Contract\ContractValidator;
use TamasLabs\Aura\Tests\Fixtures\InlineTable;
use TamasLabs\Aura\Tests\Fixtures\TypedUser;
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

/**
 * A table built from a column list.
 *
 * @param  list<Column|ColumnGroup>  $columns
 * @return AuraTable<TypedUser>
 */
function auraTable(
    array $columns,
    ?Footer $footer = null,
    ?TableSettings $settings = null,
    ?CellRules $rowRules = null,
    ?string $resource = null,
): AuraTable {
    return new InlineTable($columns, $footer, $settings, $rowRules, $resource);
}

/**
 * Read a value out of a built definition or response by path.
 *
 * The definition is `array<string, mixed>` — it has to be, the contract is not a
 * fixed shape — so every step into it is `mixed`. This narrows once, at the
 * point where the test says which path it means, and fails with that path rather
 * than with a type error twelve frames away.
 */
function auraDig(mixed $value, string|int ...$path): mixed
{
    $walked = [];

    foreach ($path as $step) {
        $walked[] = (string) $step;

        if (! is_array($value) || ! array_key_exists($step, $value)) {
            Assert::fail('Nothing at '.implode('.', $walked).' in the payload.');
        }

        $value = $value[$step];
    }

    return $value;
}

/**
 * The same, where the test needs an array back.
 *
 * @return array<array-key, mixed>
 */
function auraDigArray(mixed $value, string|int ...$path): array
{
    $found = auraDig($value, ...$path);

    Assert::assertIsArray($found, 'Expected an array at '.implode('.', array_map(strval(...), $path)).'.');

    return $found;
}

/**
 * The header cell with this key, from a built definition.
 *
 * @param  array<string, mixed>  $definition
 * @return array<string, mixed>|null
 */
function auraCell(array $definition, string $key): ?array
{
    $header = $definition['header'] ?? [];
    $rows = is_array($header) ? ($header['rows'] ?? []) : [];

    foreach (is_array($rows) ? $rows : [] as $row) {
        $cells = is_array($row) ? ($row['cells'] ?? []) : [];

        foreach (is_array($cells) ? $cells : [] as $cell) {
            if (is_array($cell) && ($cell['key'] ?? null) === $key) {
                /** @var array<string, mixed> $cell */
                return $cell;
            }
        }
    }

    return null;
}

/**
 * Re-decode a value the way the browser will receive it — objects, not
 * associative arrays, because JSON Schema tells `{}` and `[]` apart.
 */
function auraObject(mixed $value): mixed
{
    return json_decode((string) json_encode($value, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
}

/**
 * Assert that these header cells are ones Aura would accept.
 *
 * @param  list<array<string, mixed>>  $cells
 */
function assertMatchesAuraHeader(array $cells): void
{
    assertMatchesAuraResponseSchema(auraObject(['header' => ['rows' => [['cells' => $cells]]]]));
}

/**
 * Assert that this cell configuration is one Aura would accept, in the smallest
 * response that can carry it.
 *
 * @param  array<string, mixed>  $config
 */
function assertMatchesAuraConfig(array $config, string $field = 'demo'): void
{
    assertMatchesAuraResponseSchema(auraObject([
        'header' => ['rows' => [['cells' => [['content' => 'Demo', 'field' => $field, 'key' => $field]]]]],
        'body' => ['columnConfigs' => [$field => $config]],
    ]));
}
