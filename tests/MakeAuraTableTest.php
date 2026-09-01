<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;

use function Pest\Laravel\artisan;

use PHPUnit\Framework\Assert;
use TamasLabs\Aura\Cell\Text;
use TamasLabs\Aura\Table\AuraTable;
use TamasLabs\Aura\Tests\Fixtures\Post;
use TamasLabs\Aura\Tests\Fixtures\TypedUser;
use TamasLabs\Aura\Tests\Fixtures\User;

/**
 * `artisan()` is typed `PendingCommand|int` — it answers an `int` once the
 * command has run. Narrowing once here keeps every call site readable.
 *
 * @param  array<string, mixed>  $arguments
 */
function auraArtisan(string $command, array $arguments = []): PendingCommand
{
    $pending = artisan($command, $arguments);

    Assert::assertInstanceOf(PendingCommand::class, $pending);

    return $pending;
}

/**
 * Where the generator writes, inside the Testbench skeleton.
 */
function auraGeneratedPath(string $class): string
{
    return app()->basePath('app/Tables/'.$class.'.php');
}

/**
 * Generate a table class and hand back its source.
 */
function auraGenerate(string $class, ?string $model = TypedUser::class): string
{
    $arguments = ['name' => $class];

    if ($model !== null) {
        $arguments['--model'] = $model;
    }

    auraArtisan('make:aura-table', $arguments)->run();

    return (string) file_get_contents(auraGeneratedPath($class));
}

afterEach(function (): void {
    File::deleteDirectory(app()->basePath('app/Tables'));
});

it('generates a class that serves a request', function (): void {
    $source = auraGenerate('GeneratedUserTable');

    // The whole point of the step: not that the file looks right, but that the
    // class in it answers a request. Required by hand because the skeleton's
    // `App\` namespace is not in this package's autoloader.
    require_once auraGeneratedPath('GeneratedUserTable');

    $class = 'App\Tables\GeneratedUserTable';

    // The class is written by the line above and required by the one before
    // that; there is nothing for static analysis to find.
    // @phpstan-ignore class.notFound
    $table = new $class;

    Assert::assertInstanceOf(AuraTable::class, $table);

    TypedUser::create([
        'first_name' => 'Ada', 'last_name' => 'Lovelace', 'balance' => 100,
        'status' => 'active', 'created_at' => '2024-01-01 10:00:00',
    ]);

    $response = $table->respond(auraHttpRequest([
        'page' => 1,
        'paginate' => 10,
        'sortable' => [['field' => 'last_name', 'direction' => 'asc']],
    ]));

    expect(auraDigArray($response, 'items'))->toHaveCount(1);

    assertMatchesAuraResponseSchema(auraObject($response));

    expect($source)->toContain('namespace App\Tables;')
        ->and($source)->toContain('@extends AuraTable<TypedUser>');
});

it('reads the flags off the schema and the casts', function (): void {
    $source = auraGenerate('SchemaUserTable');

    expect($source)
        // A string column: read, sorted, searched.
        ->toContain("Column::make('last_name')->sortable()->searchable(),")
        // An enum cast is the only thing that tells a `varchar` from a set of
        // options, and it fills the filter's `elements` at build time.
        ->toContain("Column::make('status')->filterable(),")
        // A decimal is sortable text here; `currency` and the alignment come
        // from the cast when the definition is built.
        ->toContain("Column::make('balance')->sortable()->searchable(),")
        ->toContain("Column::make('created_at')->sortable()->searchable(),");
});

it('re-keys the selection column so the actions can take the primary key', function (): void {
    $source = auraGenerate('KeyedUserTable');

    // The collision every table hits first: both columns default to the
    // model's key, and the action column is the one that cannot move.
    expect($source)->toContain("Column::selection()->key('select'),")
        ->and($source)->toContain("Column::actions('id', Action::show(), Action::edit(), Action::destroy()),");
});

it('leaves a foreign key to a hand-written relation column', function (): void {
    $source = auraGenerate('RelationUserTable');

    expect($source)->toContain("// company_id is a foreign key. Column::make('company.name') renders the")
        ->and($source)->not->toContain("Column::make('company_id')");
});

it('skips the primary key and anything hidden', function (): void {
    $source = auraGenerate('HiddenUserTable', User::class);

    expect($source)->not->toContain("Column::make('id')");
});

it('guesses the model from the class name', function (): void {
    // `make:policy` guesses the same way, and a table is almost always named
    // after the model it pages through. The guess is visible in the refusal:
    // nothing here defines App\Models\Customer.
    auraArtisan('make:aura-table', ['name' => 'CustomerTable'])
        ->expectsOutputToContain('App\Models\Customer')
        ->assertFailed()
        ->run();

    expect(auraGeneratedPath('CustomerTable'))->not->toBeFile();
});

it('scaffolds a model whose table is in another namespace', function (): void {
    $source = auraGenerate('PostsTable', Post::class);

    // `qualifyModel()` would have made this App\Models\TamasLabs\Aura\…
    expect($source)->toContain('use '.Post::class.';')
        ->and($source)->toContain("Column::make('title')->sortable()->searchable(),");
});

it('refuses a model that is not one', function (): void {
    auraArtisan('make:aura-table', ['name' => 'BogusTable', '--model' => Text::class])
        ->expectsOutputToContain('is not an Eloquent model')
        ->assertFailed()
        ->run();

    expect(auraGeneratedPath('BogusTable'))->not->toBeFile();
});
