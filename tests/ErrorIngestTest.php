<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;
use TamasLabs\Aura\AuraServiceProvider;
use TamasLabs\Aura\Errors\AuraErrorRecord;
use TamasLabs\Aura\Errors\ErrorContext;
use TamasLabs\Aura\Errors\ErrorIngestConfig;
use TamasLabs\Aura\Errors\ErrorStore;
use TamasLabs\Aura\Errors\StoreErrorReport;
use TamasLabs\AuraSchema\AuraSchema;

/**
 * Switch the ingest on inside an already-booted test application.
 *
 * `aura.errors.enabled` is read in the provider's `boot()`, so setting it from
 * a test comes too late on its own — re-registering the provider with `force`
 * runs `boot()` again against the new config, which is what registers the
 * route. The middleware is emptied because Testbench boots with no middleware
 * aliases, so the packaged `throttle:60,1` has nothing to resolve against; the
 * default itself is asserted from the config file, where the claim lives.
 *
 * @param  array<string, mixed>  $config
 */
function auraEnableIngest(array $config = []): void
{
    config()->set('aura.errors.enabled', true);
    config()->set('aura.errors.middleware', []);

    foreach ($config as $key => $value) {
        config()->set('aura.errors.'.$key, $value);
    }

    app()->register(new AuraServiceProvider(app()), true);
}

/**
 * Create the table the `database` driver writes to, by running the migration
 * that gets published — so the stub is covered rather than merely shipped.
 */
function auraErrorsTable(): void
{
    /** @var object $migration */
    $migration = require __DIR__.'/../database/migrations/create_aura_errors_table.php.stub';

    if (method_exists($migration, 'up')) {
        $migration->up();
    }
}

/**
 * The row the ingest wrote, or a failure naming that it wrote none.
 */
function auraErrorRow(): stdClass
{
    $row = DB::table('aura_errors')->first();

    if (! $row instanceof stdClass) {
        Assert::fail('Nothing was written to aura_errors.');
    }

    return $row;
}

/**
 * A column value the test expects to be text.
 */
function auraText(mixed $value): string
{
    if (! is_string($value)) {
        Assert::fail('Expected a string column value, got '.get_debug_type($value).'.');
    }

    return $value;
}

/**
 * A column value the test expects to be a number.
 */
function auraNumber(mixed $value): int
{
    if (! is_numeric($value)) {
        Assert::fail('Expected a numeric column value, got '.get_debug_type($value).'.');
    }

    return (int) $value;
}

/**
 * One entry the contract accepts, with anything the test cares about overridden.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function auraErrorEntry(array $overrides = []): array
{
    return array_merge([
        'severity' => 'warning',
        'level' => 'warning',
        'timestamp' => '2026-09-02T12:00:00.000Z',
        'component' => 'HeaderValidator',
        'action' => 'validate',
        'type' => 'validation',
        'message' => 'Invalid header structure in API response',
        'key' => 'header',
    ], $overrides);
}

/**
 * POST a batch the way Aura does: a JSON body, and no other header.
 *
 * @param  list<array<string, mixed>>  $entries
 * @return TestResponse<Response>
 */
function auraPostErrors(array $entries): TestResponse
{
    return postJson('/aura/errors', ['errors' => $entries]);
}

it('registers no route until it is switched on', function (): void {
    // A telemetry endpoint must not appear because someone ran `composer
    // require`; `enabled` is false in the packaged config.
    postJson('/aura/errors', ['errors' => []])->assertNotFound();
});

it('accepts a full batch and answers 202', function (): void {
    auraEnableIngest();

    $entries = [];

    for ($i = 0; $i < 100; $i++) {
        $entries[] = auraErrorEntry(['timestamp' => sprintf('2026-09-02T12:00:%02d.000Z', $i)]);
    }

    auraPostErrors($entries)
        ->assertStatus(202)
        ->assertJson(['received' => 100, 'stored' => 100, 'dropped' => 0]);
});

it('drops a malformed entry, keeps the rest, and still answers 202', function (): void {
    // The rule the whole response contract rests on: the client cannot fix a
    // batch, so a 4xx here would come back four times and then forever.
    auraEnableIngest(['driver' => 'database']);
    auraErrorsTable();

    $entry = auraErrorEntry();
    unset($entry['type']);

    auraPostErrors([auraErrorEntry(), $entry, auraErrorEntry(['timestamp' => '2026-09-02T12:00:01.000Z'])])
        ->assertStatus(202)
        ->assertJson(['received' => 3, 'stored' => 2, 'dropped' => 1])
        ->assertJsonPath('reasons.0', 'entry 1: The type field is required.');

    expect(DB::table('aura_errors')->count())->toBe(2);
});

it('drops entries over the configured ceiling without rejecting the batch', function (): void {
    auraEnableIngest(['max_entries' => 2]);

    $entries = [];

    for ($i = 0; $i < 5; $i++) {
        $entries[] = auraErrorEntry(['timestamp' => sprintf('2026-09-02T12:00:%02d.000Z', $i)]);
    }

    auraPostErrors($entries)
        ->assertStatus(202)
        ->assertJson(['received' => 5, 'stored' => 2, 'dropped' => 3]);
});

it('answers 413 over the payload ceiling', function (): void {
    // One of the three codes worth spending: the client cannot send a smaller
    // batch, but the alternative is an unbounded body from the browser.
    auraEnableIngest(['max_payload' => 200]);

    auraPostErrors([auraErrorEntry(['details' => str_repeat('x', 500)])])
        ->assertStatus(413);
});

it('answers 202 for a body carrying no errors array', function (): void {
    // Nothing Aura sends looks like this, so it is a broken client — and a
    // broken client that gets a 4xx retries it four times and then forever.
    auraEnableIngest();

    postJson('/aura/errors', ['nonsense' => true])
        ->assertStatus(202)
        ->assertJson(['received' => 0, 'stored' => 0, 'dropped' => 0]);
});

it('carries no CSRF middleware in the packaged default', function (): void {
    // Aura reports with a native fetch() and sends no token, so the `web` group
    // would answer 419 — and the client would retry that batch forever.
    $middleware = ErrorIngestConfig::MIDDLEWARE;

    expect($middleware)->not->toContain('web')
        ->and(implode(' ', $middleware))->not->toContain('Csrf')
        ->and($middleware)->toBe(config('aura.errors.middleware'));
});

it('rate-limits in the packaged default', function (): void {
    // The throttle is the only cap on the endpoint: nothing authenticates it,
    // and the fingerprint deduplicates repeats of one error rather than a flood
    // of varied ones. Dropping it from the default would be a silent change of
    // what an unauthenticated route can write.
    $middleware = ErrorIngestConfig::MIDDLEWARE;

    expect($middleware)->toContain('throttle:60,1')
        ->and(config('aura.errors.middleware'))->toContain('throttle:60,1');
});

it('says how to close the endpoint, in both references and in the config file', function (): void {
    // The route is unauthenticated by design, which makes the guidance the only
    // mitigation the package ships. It has to say what to add as well as what
    // not to — and it has to say it in both languages, which is how the two
    // references drift apart.
    foreach (['README.en.md', 'README.hu.md'] as $reference) {
        $readme = auraPackageFile($reference);

        expect($readme)->toContain("'middleware' => ['throttle:60,1', 'auth'],")
            ->and($readme)->toContain('credentials')
            ->and($readme)->toContain('client_max_body_size');
    }

    $config = auraPackageFile('config/aura.php');

    expect($config)->toContain("'middleware' => ['throttle:60,1', 'auth'],")
        ->and($config)->toContain('client_max_body_size');
});

it('writes one log line per record on the log driver', function (): void {
    auraEnableIngest(['driver' => 'log']);

    Log::shouldReceive('channel')->once()->with(null)->andReturnSelf();
    Log::shouldReceive('log')
        ->once()
        ->withArgs(function (string $level, string $message, array $context): bool {
            return $level === 'warning'
                && str_contains($message, '[header]')
                && $context['fingerprint'] !== ''
                // `level` is a verbatim copy of `severity`; storing both would
                // only invite them to disagree.
                && ! array_key_exists('level', $context);
        });

    auraPostErrors([auraErrorEntry()])->assertStatus(202);
});

it('stores one row for the same batch delivered four times', function (): void {
    // Aura retries a batch four times on any non-2xx answer and then repeats it
    // behind a backoff, so this is the ordinary case, not the edge one.
    auraEnableIngest(['driver' => 'database']);
    auraErrorsTable();

    for ($attempt = 0; $attempt < 4; $attempt++) {
        auraPostErrors([auraErrorEntry(['count' => 3])])->assertStatus(202);
    }

    $row = auraErrorRow();

    expect(DB::table('aura_errors')->count())->toBe(1)
        // The server's number: four arrivals.
        ->and(auraNumber($row->receipts))->toBe(4)
        // The client's number: three occurrences in the browser. Deliberately
        // not multiplied by the retries.
        ->and(auraNumber($row->occurrences))->toBe(3);
});

it('reports only the first delivery as stored', function (): void {
    auraEnableIngest(['driver' => 'database']);
    auraErrorsTable();

    auraPostErrors([auraErrorEntry()])->assertJson(['stored' => 1]);
    auraPostErrors([auraErrorEntry()])->assertJson(['stored' => 0, 'received' => 1]);
});

it('writes a whole batch in two queries', function (): void {
    // The record-at-a-time version cost two queries per entry — a SELECT and
    // then an INSERT or an UPDATE — which is 200 on a default-capped batch,
    // synchronously, inside the request; behind `throttle:60,1` that is 12 000
    // queries a minute from one IP. The count has to stay independent of the
    // batch size, so it is pinned rather than described.
    auraEnableIngest(['driver' => 'database']);
    auraErrorsTable();

    $entries = [];

    for ($i = 0; $i < 100; $i++) {
        $entries[] = auraErrorEntry(['message' => "Error number {$i}"]);
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    auraPostErrors($entries)->assertJson(['stored' => 100]);

    expect($queries)->toBe(2);

    // The repeat is the ordinary case, not the edge one — Aura re-sends a batch
    // after any non-2xx answer — so it must not degrade to a query per row.
    $queries = 0;

    auraPostErrors($entries)->assertJson(['stored' => 0]);

    expect($queries)->toBe(2)
        ->and(DB::table('aura_errors')->count())->toBe(100);
});

it('folds a fingerprint repeated inside one batch', function (): void {
    // One statement must not carry one key twice, and the drivers disagree
    // about what happens when it does: PostgreSQL refuses the whole `ON
    // CONFLICT` statement, while SQLite and MySQL accept it and let the last row
    // win — silently wrong counters rather than an error. The fold therefore
    // happens before the write, the same way on every driver.
    auraEnableIngest(['driver' => 'database']);
    auraErrorsTable();

    // `count` is not part of the fingerprint, so these are one error.
    auraPostErrors([
        auraErrorEntry(['count' => 2]),
        auraErrorEntry(['count' => 5]),
    ])->assertJson(['received' => 2, 'stored' => 1]);

    $row = auraErrorRow();

    expect(DB::table('aura_errors')->count())->toBe(1)
        // Two arrivals: the server's number.
        ->and(auraNumber($row->receipts))->toBe(2)
        // The client's number, and the higher of the two — never the sum.
        ->and(auraNumber($row->occurrences))->toBe(5);
});

it('keeps two errors a millisecond apart apart', function (): void {
    auraEnableIngest(['driver' => 'database']);
    auraErrorsTable();

    auraPostErrors([
        auraErrorEntry(['timestamp' => '2026-09-02T12:00:00.000Z']),
        auraErrorEntry(['timestamp' => '2026-09-02T12:00:00.001Z']),
    ]);

    expect(DB::table('aura_errors')->count())->toBe(2);
});

it('stores the fields the contract carries, and drops level', function (): void {
    auraEnableIngest(['driver' => 'database']);
    auraErrorsTable();

    auraPostErrors([auraErrorEntry([
        'details' => 'rows.0.cells.1.field: Required',
        'count' => 2,
        'lastTimestamp' => '2026-09-02T12:01:00.000Z',
        'metadata' => ['receivedType' => 'object'],
    ])]);

    $row = auraErrorRow();

    expect($row->severity)->toBe('warning')
        ->and($row->type)->toBe('validation')
        ->and($row->component)->toBe('HeaderValidator')
        // `error_key`, not `key`: the word is reserved in MySQL.
        ->and($row->error_key)->toBe('header')
        ->and($row->details)->toBe('rows.0.cells.1.field: Required')
        ->and(json_decode(auraText($row->metadata), true))->toBe(['receivedType' => 'object'])
        ->and($row->last_occurred_at)->not->toBeNull();
});

it('truncates metadata over the budget instead of dropping the entry', function (): void {
    // `HeaderValidator` puts the whole rejected header section in
    // `receivedValue`, and the client bounds nothing on the way out.
    auraEnableIngest(['driver' => 'database', 'metadata.max_bytes' => 64]);
    auraErrorsTable();

    auraPostErrors([auraErrorEntry([
        'metadata' => ['receivedValue' => str_repeat('x', 500), 'receivedType' => 'string'],
    ])])->assertJson(['stored' => 1]);

    /** @var array<string, mixed> $metadata */
    $metadata = json_decode(auraText(auraErrorRow()->metadata), true);

    expect($metadata)->toHaveKey('_truncated')
        ->and($metadata['_keys'] ?? null)->toBe(['receivedValue', 'receivedType']);
});

it('stores no metadata at all when it is switched off', function (): void {
    // The one field that can carry what a user typed: SessionStateValidator
    // reports the persisted session state, search terms and all.
    auraEnableIngest(['driver' => 'database', 'metadata.store' => false]);
    auraErrorsTable();

    auraPostErrors([auraErrorEntry(['metadata' => ['receivedValue' => 'secret term']])]);

    expect(DB::table('aura_errors')->value('metadata'))->toBeNull();
});

it('records the context the payload does not carry', function (): void {
    // Aura sends no storeId, no URL and no version, so every one of these is
    // the server's approximation of a question the payload cannot answer.
    auraEnableIngest(['driver' => 'database']);
    auraErrorsTable();

    withHeaders(['referer' => 'https://example.test/users', 'user-agent' => 'Mozilla/5.0'])
        ->postJson('/aura/errors', ['errors' => [auraErrorEntry()]]);

    $row = auraErrorRow();

    expect($row->referer)->toBe('https://example.test/users')
        ->and($row->user_agent)->toBe('Mozilla/5.0')
        ->and($row->received_at)->not->toBeNull();
});

it('queues the write when the queue is switched on', function (): void {
    auraEnableIngest(['driver' => 'database', 'queue' => true]);
    auraErrorsTable();

    Queue::fake();

    auraPostErrors([auraErrorEntry()])->assertJson(['stored' => 1]);

    Queue::assertPushed(StoreErrorReport::class);
    expect(DB::table('aura_errors')->count())->toBe(0);
});

it('accepts the batch the contract ships as an example', function (): void {
    // The fixtures above are this package's idea of what Aura sends; this one
    // is the contract's, decoded straight out of the schema package.
    auraEnableIngest(['driver' => 'database']);
    auraErrorsTable();

    $example = auraJsonFile(AuraSchema::examplePath('error-report'));

    assertMatchesAuraErrorReportSchema($example);

    /** @var array{errors: list<array<string, mixed>>} $decoded */
    $decoded = json_decode((string) json_encode($example), true);

    auraPostErrors($decoded['errors'])
        ->assertStatus(202)
        ->assertJson(['received' => 3, 'stored' => 3, 'dropped' => 0]);
});

it('builds a fingerprint from the fields that do not move', function (): void {
    $first = AuraErrorRecord::fingerprint('header', 'HeaderValidator', 'validate', 'validation', 'Broken', '2026-09-02T12:00:00.000Z');
    $same = AuraErrorRecord::fingerprint('header', 'HeaderValidator', 'validate', 'validation', 'Broken', '2026-09-02T12:00:00.000Z');
    $later = AuraErrorRecord::fingerprint('header', 'HeaderValidator', 'validate', 'validation', 'Broken', '2026-09-02T12:00:00.001Z');

    expect($first)->toBe($same)->and($first)->not->toBe($later);
});

it('reads the configured section with defaults for anything unreadable', function (): void {
    config()->set('aura.errors.max_payload', 'not a number');
    config()->set('aura.errors.max_entries', 0);
    config()->set('aura.errors.middleware', 'not a list');

    $config = ErrorIngestConfig::fromConfig();

    // A ceiling a broken config can switch off is not a ceiling.
    expect($config->maxPayload)->toBe(ErrorIngestConfig::MAX_PAYLOAD)
        ->and($config->maxEntries)->toBe(ErrorIngestConfig::MAX_ENTRIES)
        ->and($config->middleware)->toBe(ErrorIngestConfig::MIDDLEWARE);
});

it('lists reported errors grouped by key', function (): void {
    auraEnableIngest(['driver' => 'database']);
    auraErrorsTable();

    auraPostErrors([
        auraErrorEntry(['count' => 2]),
        auraErrorEntry(['timestamp' => '2026-09-02T12:00:01.000Z', 'count' => 1]),
        auraErrorEntry(['key' => 'body', 'component' => 'BodyValidator']),
    ]);
    // The same batch again: retries move `receipts`, not `occurrences`.
    auraPostErrors([auraErrorEntry(['count' => 2])]);

    auraArtisan('aura:errors')
        ->expectsOutputToContain('header')
        ->expectsOutputToContain('BodyValidator')
        ->assertSuccessful();
});

it('refuses to list anything under the log driver', function (): void {
    // An empty table would read as good news; there is simply nothing to read.
    auraEnableIngest(['driver' => 'log']);

    auraArtisan('aura:errors')
        ->expectsOutputToContain('nothing to read')
        ->assertFailed();
});

it('filters the listing by key and severity', function (): void {
    auraEnableIngest(['driver' => 'database']);
    auraErrorsTable();

    auraPostErrors([
        auraErrorEntry(),
        auraErrorEntry(['key' => 'body', 'component' => 'BodyValidator', 'severity' => 'error', 'level' => 'error']),
    ]);

    auraArtisan('aura:errors --key=body')
        ->expectsOutputToContain('BodyValidator')
        ->doesntExpectOutputToContain('HeaderValidator')
        ->assertSuccessful();

    auraArtisan('aura:errors --severity=warning')
        ->expectsOutputToContain('HeaderValidator')
        ->doesntExpectOutputToContain('BodyValidator')
        ->assertSuccessful();
});

it('answers 202 when the store has no table to write to', function (): void {
    // The likely first run of the feature: the driver is switched to `database`
    // and the published migration has not been run yet. A 500 here would be the
    // worst possible answer — Aura retries a failed batch four times, puts it
    // back at the front of its queue and repeats it behind an exponential
    // backoff, so the broken telemetry would multiply its own traffic forever.
    Exceptions::fake();

    auraEnableIngest(['driver' => 'database']);

    auraPostErrors([auraErrorEntry(), auraErrorEntry(['key' => 'b']), auraErrorEntry(['key' => 'c'])])
        ->assertStatus(202)
        ->assertJson(['received' => 3, 'stored' => 0, 'dropped' => 0]);

    // Once, not once per record: what failed is the storage, not the entry, so
    // the batch stops at the first one instead of writing three copies of one
    // fault into the log.
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (QueryException $e): bool => true);
});

it('answers 202 when a bound store throws', function (): void {
    // The interface says an implementation must not throw, but the interface is
    // the part of this feature a host application replaces — so the guarantee
    // is enforced here rather than merely documented there.
    Exceptions::fake();

    auraEnableIngest();

    app()->bind(ErrorStore::class, fn (): ErrorStore => new class implements ErrorStore
    {
        /**
         * @param  list<AuraErrorRecord>  $records
         */
        public function store(array $records): int
        {
            throw new RuntimeException('the store is down');
        }
    });

    auraPostErrors([auraErrorEntry()])
        ->assertStatus(202)
        ->assertJson(['received' => 1, 'stored' => 0, 'dropped' => 0]);

    Exceptions::assertReported(RuntimeException::class);
});

it('answers 202 when the queue it dispatches to is unreachable', function (): void {
    // The other branch of dispatch(): with `queue` on, nothing of the store runs
    // in the request, but handing the job over can fail on its own.
    Exceptions::fake();

    auraEnableIngest(['driver' => 'database', 'queue' => true]);
    config()->set('queue.default', 'no-such-connection');

    auraPostErrors([auraErrorEntry()])
        ->assertStatus(202)
        ->assertJson(['received' => 1, 'stored' => 0, 'dropped' => 0]);

    Exceptions::assertReportedCount(1);
});

it('keeps what landed when a write fails part-way through a batch', function (): void {
    // The write is a batched `upsert`, which is one all-or-nothing statement, so
    // one poison record would otherwise cost the ninety-nine beside it. The
    // failed chunk is reported once and then walked a row at a time: `stored` is
    // the number that actually landed — not the whole batch, and not none of it
    // — and the pass stops at the offending row rather than repeating one
    // storage fault once per record.
    Exceptions::fake();

    auraEnableIngest(['driver' => 'database']);
    auraErrorsTable();

    // SQLite is the suite's only driver, and a trigger is the one way to make a
    // single row's insert fail while the table itself stays healthy.
    DB::statement(
        'CREATE TRIGGER aura_boom BEFORE INSERT ON aura_errors '
        ."WHEN NEW.error_key = 'boom' BEGIN SELECT RAISE(ABORT, 'boom'); END"
    );

    auraPostErrors([
        auraErrorEntry(['key' => 'first']),
        auraErrorEntry(['key' => 'boom']),
        auraErrorEntry(['key' => 'third']),
    ])
        ->assertStatus(202)
        ->assertJson(['received' => 3, 'stored' => 1, 'dropped' => 0]);

    expect(DB::table('aura_errors')->pluck('error_key')->all())->toBe(['first']);

    Exceptions::assertReportedCount(1);
});

it('writes through the store when the queued job runs', function (): void {
    // Queue::fake() proves the dispatch; this proves the other half — that what
    // was dispatched still writes when a worker picks it up.
    auraEnableIngest(['driver' => 'database']);
    auraErrorsTable();

    $record = AuraErrorRecord::fromEntry(
        auraErrorEntry(),
        new ErrorContext(receivedAt: '2026-09-02T12:00:00.000Z'),
        ErrorIngestConfig::fromConfig(),
    );

    (new StoreErrorReport([$record]))->handle(app(ErrorStore::class));

    expect(DB::table('aura_errors')->count())->toBe(1);
});
