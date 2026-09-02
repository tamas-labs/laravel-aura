<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use TamasLabs\Aura\Tests\Docs\PublicSurface;

/**
 * Public-surface guard.
 *
 * `DocsCoverageTest` proves every public method is written down somewhere, and
 * `VersioningTest` proves the promise is stated. Neither notices the promise
 * itself changing: a new public method is documented and the surface is one
 * method wider, an `@internal` on a documented method narrows it, and both read
 * as ordinary commits.
 *
 * `tests/Docs/public-surface.txt` is therefore committed, and this test rebuilds
 * the list beside it. It refuses nothing — it only makes the direction explicit,
 * because widening is a minor release and narrowing is a major one, and after
 * the `1.0.0` tag that difference is the whole of semver.
 *
 * The Vue package guards its own `index.ts` exports the same way.
 */
$snapshot = __DIR__.'/Docs/public-surface.txt';

/**
 * The committed surface, comments and blank lines dropped.
 *
 * @return list<string>
 */
function auraRecordedSurface(string $path): array
{
    $contents = file_get_contents($path);

    Assert::assertNotFalse($contents, 'Cannot read the recorded public surface');

    $lines = preg_split('/\R/', $contents);

    Assert::assertIsArray($lines);

    return array_values(array_filter(
        array_map(trim(...), $lines),
        fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
    ));
}

/**
 * The surface as the code actually declares it today.
 *
 * @return list<string>
 */
function auraDeclaredSurface(): array
{
    $lines = array_map(
        fn (array $entry): string => str_replace('TamasLabs\\Aura\\', '', $entry['class']).'::'.$entry['method'],
        PublicSurface::methods(),
    );

    $lines = array_values(array_unique($lines));
    sort($lines);

    return $lines;
}

it('has not widened or narrowed the public surface unnoticed', function () use ($snapshot) {
    $recorded = auraRecordedSurface($snapshot);
    $declared = auraDeclaredSurface();

    // Reported as two lists rather than one diff: the directions are different
    // release decisions, and a commit that does both wants to see both.
    $added = array_values(array_diff($declared, $recorded));
    $removed = array_values(array_diff($recorded, $declared));

    $report = '';

    if ($added !== []) {
        $report .= "\nThe version promise widened — these are new (document them in both full "
            ."references, or mark them @internal), then add them to public-surface.txt:\n  "
            .implode("\n  ", $added)."\n";
    }

    if ($removed !== []) {
        $report .= "\nThe version promise narrowed — these are gone, which is a breaking change "
            ."(an @internal on a documented method is one too):\n  ".implode("\n  ", $removed)."\n";
    }

    Assert::assertSame([[], []], [$added, $removed], $report);
});

it('records a surface, and records it once', function () use ($snapshot) {
    // A guard that silently recorded nothing would pass for the wrong reason,
    // and a duplicated line would let a removal cancel out against it.
    $recorded = auraRecordedSurface($snapshot);

    expect($recorded)->toHaveCount(count(array_unique($recorded)))
        ->and(count($recorded))->toBeGreaterThan(200);
});

it('names methods that resolve, by a short name only one class answers to', function () use ($snapshot) {
    // The record identifies a class by its short name, which reads far better
    // in a diff than the namespace repeated 246 times — and is only unambiguous
    // while no two classes under src/ share one. That is asserted rather than
    // assumed, because the day it stops being true the record starts lying
    // about which class made the promise.
    $classes = [];

    foreach (PublicSurface::classes() as $class) {
        $short = (new ReflectionClass($class))->getShortName();

        Assert::assertArrayNotHasKey(
            $short,
            $classes,
            "Two classes under src/ are both called {$short}, so the surface record cannot name them apart",
        );

        $classes[$short] = $class;
    }

    foreach (auraRecordedSurface($snapshot) as $line) {
        [$short, $method] = explode('::', $line, 2);

        Assert::assertArrayHasKey($short, $classes, "public-surface.txt names {$short}, which does not exist");
        Assert::assertTrue(
            method_exists($classes[$short], $method),
            "public-surface.txt names {$line}, which does not exist",
        );
    }
});
