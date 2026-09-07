<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use TamasLabs\Aura\AuraServiceProvider;
use TamasLabs\Aura\Query\FieldPermissions;
use TamasLabs\Aura\Request\AuraRequest;
use TamasLabs\Aura\Request\RequestLimits;
use TamasLabs\Aura\Support\Messages;

/**
 * The lines one packaged locale carries.
 *
 * @return array<string, string>
 */
function auraLines(string $locale): array
{
    /** @var array<string, string> $lines */
    $lines = require __DIR__.'/../lang/'.$locale.'/validation.php';

    return $lines;
}

/**
 * A request refused for the reason this test is about, as its message.
 *
 * @param  array<string, mixed>  $payload
 */
function auraRefusal(array $payload, FieldPermissions $fields, ?RequestLimits $limits = null): string
{
    try {
        AuraRequest::fromArray($payload, $fields, $limits);
    } catch (ValidationException $e) {
        return implode(' ', array_map(
            fn (mixed $message): string => is_string($message) ? $message : '',
            Arr::flatten($e->errors()),
        ));
    }

    throw new RuntimeException('The payload was accepted.');
}

it('resolves the package namespace out of the box', function (): void {
    // Loaded rather than published: a host that publishes nothing still gets a
    // sentence, not the key.
    expect(Messages::get('validation.not_sortable', ['field' => 'email']))
        ->toBe('The field "email" cannot be sorted by.');
});

it('answers in the application locale', function (): void {
    app()->setLocale('hu');

    expect(Messages::get('validation.not_sortable', ['field' => 'email']))
        ->toBe('A(z) "email" mező nem rendezhető.');
});

it('carries the same keys in every packaged locale', function (): void {
    // The only thing that keeps two translation files together. A key added to
    // one and forgotten in the other is a message that silently falls back to
    // English for half the users of a locale that claims to be complete.
    $en = array_keys(auraLines('en'));
    $hu = array_keys(auraLines('hu'));

    sort($en);
    sort($hu);

    expect($hu)->toBe($en)->and($en)->not->toBeEmpty();
});

it('declares the same placeholders in every packaged locale', function (): void {
    // The failure a lang file has that a `sprintf()` did not: `sprintf()` raises
    // on the wrong number of arguments, a translator who drops `:field` just
    // ships a sentence that names nothing.
    $placeholders = static function (string $line): array {
        preg_match_all('/:([a-z_]+)/', $line, $matches);

        $names = array_unique($matches[1]);
        sort($names);

        return $names;
    };

    $en = auraLines('en');

    foreach (auraLines('hu') as $key => $line) {
        expect($placeholders($line))->toBe(
            $placeholders($en[$key]),
            "aura::validation.{$key} does not declare the same placeholders in both locales",
        );
    }
});

it('degrades to the key when a line is not a string', function (): void {
    // Asking for the group rather than a line in it: the translator answers the
    // whole array, which is exactly what a published file with a stray `return
    // []` under a key would produce. A 500 here would be the one outcome this
    // package spends the most effort avoiding.
    expect(Messages::get('validation'))->toBe('aura::validation');
});

it('renders every refusal AuraRequest can raise, in Hungarian', function (): void {
    app()->setLocale('hu');

    $fields = new FieldPermissions(sortable: ['last_name'], searchable: ['last_name'], filterable: ['status']);
    $base = ['page' => 1, 'paginate' => 25];

    expect(auraRefusal($base + ['nope' => 1], $fields))->toContain('nem definiál')
        ->and(auraRefusal($base + ['sortable' => [['field' => 'email', 'direction' => 'asc']]], $fields))
        ->toContain('nem rendezhető')
        ->and(auraRefusal($base + ['searchable' => [['field' => 'email', 'term' => 'x']]], $fields))
        ->toContain('nem lehet keresni')
        ->and(auraRefusal($base + ['filterable' => [['field' => 'email', 'values' => ['x']]]], $fields))
        ->toContain('nem szűrhető');
});

it('renders the bounded-list refusals in Hungarian', function (): void {
    app()->setLocale('hu');

    $fields = new FieldPermissions(sortable: ['last_name']);
    $base = ['page' => 1, 'paginate' => 25];

    $tooLong = $base + ['sortable' => [
        ['field' => 'last_name', 'direction' => 'asc'],
        ['field' => 'other', 'direction' => 'asc'],
    ]];

    expect(auraRefusal($tooLong, $fields))->toContain('legfeljebb egy elemet küld')
        ->and(auraRefusal(
            $base + ['selected' => [1, 2, 3]],
            $fields,
            new RequestLimits(selected: 2),
        ))->toContain('azonosítót hordoz');
});

it('renders the duplicate-field refusal in Hungarian', function (): void {
    app()->setLocale('hu');

    $fields = new FieldPermissions(sortable: ['last_name', 'first_name']);

    $payload = ['page' => 1, 'paginate' => 25, 'sortable' => [
        ['field' => 'last_name', 'direction' => 'asc'],
        ['field' => 'last_name', 'direction' => 'desc'],
    ]];

    expect(auraRefusal($payload, $fields))->toContain('többször nevezi meg');
});

it('renders the scalar rules in Hungarian', function (): void {
    app()->setLocale('hu');

    $fields = new FieldPermissions(searchable: ['last_name'], filterable: ['status']);
    $base = ['page' => 1, 'paginate' => 25];

    expect(auraRefusal($base + ['searchable' => [['field' => 'last_name', 'min' => ['x']]]], $fields))
        ->toContain('szövegnek vagy számnak')
        ->and(auraRefusal($base + ['filterable' => [['field' => 'status', 'values' => [['x']]]]], $fields))
        ->toContain('logikai értéknek');
});

it('leaves the definition errors in English', function (): void {
    app()->setLocale('hu');

    // A message addressed to whoever wrote the table, read in a stack trace.
    // Nothing under Exceptions/ goes through the translator, and this is the
    // statement of that.
    $source = auraPackageFile('src/Exceptions/InvalidDefinition.php');

    expect($source)->not->toContain('Messages::')
        ->and(auraPackageFile('src/Exceptions/UnsupportedRelation.php'))->not->toContain('Messages::')
        ->and(auraPackageFile('src/Exceptions/UnsupportedPaginator.php'))->not->toContain('Messages::');
});

it('publishes the translations under their own tag', function (): void {
    $paths = AuraServiceProvider::pathsToPublish(AuraServiceProvider::class, 'aura-lang');

    expect($paths)->not->toBeEmpty()
        ->and(array_key_first($paths))->toEndWith('lang')
        ->and(implode('', array_map(fn (mixed $path): string => is_string($path) ? $path : '', $paths)))
        ->toContain('vendor/aura');
});

it('ships the translations in the dist', function (): void {
    // `lang/` under an `export-ignore` would leave every installed copy with no
    // messages at all — the failure would be a key rendered at a user.
    expect(auraPackageFile('.gitattributes'))->not->toContain('/lang');
});
