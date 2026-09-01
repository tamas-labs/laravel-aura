<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use TamasLabs\Aura\AuraContract;

/**
 * Versioning guard.
 *
 * Three version numbers meet in this package — its own, the contract's, and the
 * Vue table's — and only one of them decides compatibility. The READMEs say
 * which; this test keeps them saying the truth.
 *
 * Documentation drift is not a cosmetic failure here. A host application reads
 * the compatibility table to decide whether an upgrade is safe, and a stale one
 * is worse than none: it answers the question wrongly and confidently.
 * `ContractSchemaTest` already pins `AuraContract::VERSION` against the schema
 * package; this pins the prose against the constant, and the requirement lists
 * against `composer.json`.
 */
$readmes = ['README.en.md', 'README.hu.md'];

/**
 * One of the full references, as text.
 */
function auraReadme(string $file): string
{
    $contents = file_get_contents(__DIR__.'/../'.$file);

    Assert::assertNotFalse($contents, "Cannot read {$file}");

    return $contents;
}

/**
 * The package manifest, decoded.
 *
 * @return array<string, mixed>
 */
function auraManifest(): array
{
    $contents = file_get_contents(__DIR__.'/../composer.json');

    Assert::assertNotFalse($contents, 'Cannot read composer.json');

    $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

    Assert::assertIsArray($manifest);

    /** @var array<string, mixed> $manifest */
    return $manifest;
}

/**
 * One constraint from the manifest's `require` block.
 */
function auraRequires(string $package): string
{
    $constraint = auraDig(auraManifest(), 'require', $package);

    Assert::assertIsString($constraint, "composer.json does not require {$package}");

    return $constraint;
}

it('states the contract version the constant actually holds', function () use ($readmes) {
    // The constant and the version have to be on one line — the compatibility
    // table's own row. A bump that leaves the docs behind fails here.
    $stated = '/`AuraContract::VERSION`[^\n]*\*\*'.preg_quote(AuraContract::VERSION, '/').'\*\*/';

    foreach ($readmes as $readme) {
        expect(preg_match($stated, auraReadme($readme)))
            ->toBe(1, "{$readme} does not state contract version ".AuraContract::VERSION);
    }
});

it('quotes the PHP and Laravel constraints composer.json requires', function () use ($readmes) {
    $constraints = [auraRequires('php'), auraRequires('illuminate/support')];

    foreach ($readmes as $readme) {
        $contents = auraReadme($readme);

        foreach ($constraints as $constraint) {
            // `toContain()` takes needles, not a message — so the assertion
            // that has something to say says it with PHPUnit's own.
            Assert::assertStringContainsString(
                '`'.$constraint.'`',
                $contents,
                "{$readme} does not quote the constraint {$constraint} that composer.json requires",
            );
        }
    }
});

it('requires one constraint for every Illuminate component', function () {
    // The requirement list says "Laravel `^12.0 || ^13.0`" in the singular. A
    // component pinned differently would make that sentence false without
    // failing the test above.
    $components = array_keys(array_filter(
        auraDigArray(auraManifest(), 'require'),
        fn (string $package): bool => str_starts_with($package, 'illuminate/'),
        ARRAY_FILTER_USE_KEY,
    ));

    $constraints = array_unique(array_map(
        fn (string|int $package): string => auraRequires((string) $package),
        $components,
    ));

    expect($components)->not->toBeEmpty()
        ->and($constraints)->toHaveCount(1);
});

it('leaves the package version to the git tag', function () {
    // A `version` key in a library manifest is a second source of truth, and the
    // release workflow verifies the tag against the changelog, not against this.
    expect(auraManifest())->not->toHaveKey('version');
});

it('names the Vue package on the other end of the contract', function () use ($readmes) {
    foreach ($readmes as $readme) {
        expect(auraReadme($readme))->toContain('@tamas-labs/aura`');
    }
});
