<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Symfony\Component\Yaml\Yaml;
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
 * The CI workflow, parsed.
 *
 * @return array<string, mixed>
 */
function auraWorkflow(): array
{
    $contents = file_get_contents(__DIR__.'/../.github/workflows/ci.yml');

    Assert::assertNotFalse($contents, 'Cannot read .github/workflows/ci.yml');

    $workflow = Yaml::parse($contents);

    Assert::assertIsArray($workflow);

    /** @var array<string, mixed> $workflow */
    return $workflow;
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

it('ships the licence its manifest claims', function () {
    // Three separate things have to agree, and only the first is obvious.
    // `composer.json` names an SPDX identifier, the file has to be that
    // licence, and `.gitattributes` decides whether the file is in the dist at
    // all — an `export-ignore` here would leave every installed copy unlicensed
    // while the manifest went on claiming MIT.
    $path = __DIR__.'/../LICENSE';

    // Asserted before the read: `file_get_contents()` on a missing file raises
    // a warning the test runner turns into an exception, and the failure would
    // name the read rather than the absent licence.
    Assert::assertFileExists($path, 'The package has no LICENSE file');

    $licence = file_get_contents($path);

    Assert::assertNotFalse($licence);
    Assert::assertStringContainsString('MIT License', $licence);
    Assert::assertStringContainsString('tamas-labs', $licence);

    expect(auraDig(auraManifest(), 'license'))->toBe('MIT');

    $attributes = file_get_contents(__DIR__.'/../.gitattributes');

    Assert::assertNotFalse($attributes, 'Cannot read .gitattributes');
    Assert::assertDoesNotMatchRegularExpression(
        '/^\/?LICENSE\s+export-ignore/m',
        $attributes,
        'LICENSE is export-ignored, so the published package would carry no licence text',
    );
});

it('pins the contract to a tagged range, not to a branch', function () {
    // `composer.lock` is not committed (library convention), so this constraint
    // is the only thing fixing the upstream revision. A branch constraint —
    // `dev-main`, or `*` — would mean "whatever main says today": the schema
    // could turn CI red with no commit here, and an old run could not be
    // replayed. A VCS repository resolves git tags into semver versions without
    // a registry, which is what makes the tagged form possible at all.
    $constraint = auraDig(auraManifest(), 'require-dev', 'tamas-labs/aura-schema');

    Assert::assertIsString($constraint, 'composer.json does not require tamas-labs/aura-schema');
    Assert::assertDoesNotMatchRegularExpression(
        '/(^|\s)(dev-|\*)/',
        $constraint,
        "tamas-labs/aura-schema is pulled at {$constraint}, which pins nothing",
    );
});

it('lets the compatibility check see the tags it compares against', function () {
    // The check compares HEAD against the last tagged minor version, and skips
    // itself while no tag exists. Those two are a fail-open pair: with the
    // shallow clone actions/checkout does by default, `git tag -l` comes back
    // empty even in a repository that has releases, the job concludes there is
    // nothing to compare against, and it reports success having compared
    // nothing. Nobody looks twice at a green check.
    $steps = auraDigArray(auraWorkflow(), 'jobs', 'bc', 'steps');

    $checkouts = [];
    $checks = 0;

    foreach ($steps as $step) {
        if (! is_array($step)) {
            continue;
        }

        $uses = $step['uses'] ?? null;

        if (is_string($uses) && str_starts_with($uses, 'actions/checkout')) {
            $checkouts[] = is_array($step['with'] ?? null) ? $step['with'] : [];
        }

        $run = $step['run'] ?? null;

        if (is_string($run) && str_contains($run, 'composer bc-check')) {
            $checks++;
        }
    }

    expect($checkouts)->toHaveCount(1)
        ->and($checks)->toBe(1);

    Assert::assertSame(
        0,
        $checkouts[0]['fetch-depth'] ?? null,
        'The backward compatibility job clones shallowly, so it would find no tags and compare nothing',
    );
});

it('installs the compatibility checker on its own, never beside the package', function () {
    // The tool requires PHP `~8.4.0 || ~8.5.0`, and this package's floor is
    // `^8.3` with a CI leg to match: as a dev dependency it would take the whole
    // suite's floor up with it, and its `symfony/console` and `composer/composer`
    // constraints have every chance of colliding with Laravel's. `composer
    // bc-check` puts it in `build/`, where none of that meets anything.
    $manifest = auraManifest();

    foreach (['require', 'require-dev'] as $section) {
        expect(auraDigArray($manifest, $section))
            ->not->toHaveKey('roave/backward-compatibility-check');
    }

    $script = auraDigArray($manifest, 'scripts', 'bc-check');

    $commands = implode(' ', array_map(
        fn (mixed $command): string => is_string($command) ? $command : '',
        $script,
    ));

    Assert::assertStringContainsString(
        '--working-dir=build/bc-check',
        $commands,
        'The bc-check script installs the checker somewhere other than its own directory',
    );
});

it('points at the package it lives in', function () {
    // Packagist reads these; a package page with no issue tracker sends its
    // first bug report to the author's inbox, or nowhere.
    $manifest = auraManifest();

    foreach ([['homepage'], ['support', 'issues'], ['support', 'source']] as $path) {
        $url = auraDig($manifest, ...$path);

        Assert::assertIsString($url, 'composer.json is missing '.implode('.', $path));
        expect($url)->toStartWith('https://github.com/tamas-labs/laravel-aura');
    }
});

it('names the Vue package on the other end of the contract', function () use ($readmes) {
    foreach ($readmes as $readme) {
        expect(auraReadme($readme))->toContain('@tamas-labs/aura`');
    }
});
