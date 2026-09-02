<?php

declare(strict_types=1);

use TamasLabs\Aura\AuraContract;
use TamasLabs\Aura\Tests\Contract\ContractValidator;
use TamasLabs\AuraSchema\AuraSchema;

it('targets the same contract version as the schema package', function (): void {
    expect(AuraContract::VERSION)->toBe(AuraSchema::VERSION);
});

it('accepts the response example shipped with the contract', function (): void {
    // The example exercises header, body, footer, meta and links at once, so it
    // also proves all five cross-file `$ref`s resolved from disk.
    assertMatchesAuraResponseSchema(auraJsonFile(AuraSchema::examplePath('response')));
});

it('accepts the request example shipped with the contract', function (): void {
    assertMatchesAuraRequestSchema(auraJsonFile(AuraSchema::examplePath('request')));
});

it('accepts the error report example shipped with the contract', function (): void {
    assertMatchesAuraErrorReportSchema(auraJsonFile(AuraSchema::examplePath('error-report')));
});

it('rejects an error entry with no severity', function (): void {
    // The one field a receiver cannot infer: without it there is no way to tell
    // a dropped validation warning from a failed request.
    $result = ContractValidator::errorReport((object) [
        'errors' => [
            (object) [
                'level' => 'warning',
                'timestamp' => '2026-09-02T12:00:00.000Z',
                'component' => 'HeaderValidator',
                'action' => 'validate',
                'type' => 'validation',
                'message' => 'Invalid header structure in API response',
            ],
        ],
    ]);

    expect($result->valid)->toBeFalse()
        ->and(implode("\n", $result->issues))->toContain('severity');
});

it('accepts an error entry carrying a field the contract does not know', function (): void {
    // `additionalProperties: true` on the entry is deliberate (D7): the Aura
    // payload may grow a `storeId`, and a receiver that rejects the batch over
    // it would reject it forever — every non-2xx answer is retried and requeued.
    $result = ContractValidator::errorReport((object) [
        'errors' => [
            (object) [
                'severity' => 'warning',
                'level' => 'warning',
                'timestamp' => '2026-09-02T12:00:00.000Z',
                'component' => 'HeaderValidator',
                'action' => 'validate',
                'type' => 'validation',
                'message' => 'Invalid header structure in API response',
                'storeId' => 'users-table',
            ],
        ],
    ]);

    expect($result->valid)->toBeTrue($result->report());
});

it('resolves every schema document offline', function (): void {
    // A `$ref` the resolver cannot answer throws rather than validating false,
    // so a green contract test can never mean "schema not found".
    foreach (array_keys(AuraSchema::all()) as $id) {
        ContractValidator::validate(null, str_replace(AuraSchema::BASE_URI, '', (string) $id));
    }
})->throwsNoExceptions();

it('rejects a response whose header is missing', function (): void {
    $result = ContractValidator::response((object) ['items' => []]);

    expect($result->valid)->toBeFalse()
        ->and(implode("\n", $result->issues))->toContain('header');
});

it('rejects a request carrying an unknown property', function (): void {
    // `aura-request.schema.json` sets `additionalProperties: false`, so anything
    // this package invents on the request side is a contract break, not an extension.
    $result = ContractValidator::request((object) [
        'page' => 1,
        'paginate' => 15,
        'somethingWeInvented' => true,
    ]);

    expect($result->valid)->toBeFalse()
        ->and(implode("\n", $result->issues))->toContain('somethingWeInvented');
});

it('rejects pagination meta that a simplePaginate would produce', function (): void {
    // simplePaginate()/cursorPaginate() cannot supply last_page or total, and the
    // contract requires both — a named guard for the LengthAwarePaginator rule.
    $result = ContractValidator::validate(
        (object) ['current_page' => 1, 'from' => 1, 'per_page' => 15, 'to' => 15],
        'pagination.schema.json#/$defs/meta',
    );

    expect($result->valid)->toBeFalse()
        ->and(implode("\n", $result->issues))->toContain('last_page');
});

it('accepts the pagination meta a LengthAwarePaginator produces', function (): void {
    $result = ContractValidator::validate(
        (object) [
            'current_page' => 1,
            'from' => 1,
            'last_page' => 5,
            'path' => 'https://example.test/api/users',
            'per_page' => 15,
            'to' => 15,
            'total' => 68,
        ],
        'pagination.schema.json#/$defs/meta',
    );

    expect($result->valid)->toBeTrue($result->report());
});
