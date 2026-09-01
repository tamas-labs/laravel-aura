<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use TamasLabs\Aura\Table\Column;
use TamasLabs\Aura\Tests\Docs\PublicSurface;

/**
 * Documentation-coverage guard, after the `v1.0/` pattern.
 *
 * The audit that preceded the 1.0 release counted the public surface against the
 * two full references and found **130 of 284 public methods mentioned in
 * neither**. Nothing had gone wrong: the docs were written by hand and nothing
 * failed when a builder method skipped them. This test turns that count into a
 * gate — `composer quality` runs it, so a method added without its documentation
 * fails locally rather than shipping undocumented.
 *
 * The bar is a **mention in both full references**: the method's name, followed
 * by `(`, anywhere in `README.en.md` and in `README.hu.md`. Deliberately lenient
 * — a row in a reference table, a line of prose, a call in an example all count.
 * It cannot judge whether the sentence is any good; it can only prove nobody has
 * to read the source to learn the method exists, and that the two languages
 * describe the same package. `README.md` is out of scope: it is the short entry
 * point, not a reference.
 *
 * What is *not* covered is decided in {@see PublicSurface}, and the exclusions
 * are the interesting half: a method excluded from here is a method the version
 * promise does not cover either.
 */
$readmes = ['README.en.md', 'README.hu.md'];

it('mentions every public method in both full references', function () use ($readmes) {
    $documents = [];

    foreach ($readmes as $readme) {
        $contents = file_get_contents(__DIR__.'/../'.$readme);
        Assert::assertNotFalse($contents, "Cannot read {$readme}");
        $documents[$readme] = $contents;
    }

    $missing = [];

    foreach (PublicSurface::methods() as $entry) {
        $mention = '/\b'.preg_quote($entry['method'], '/').'\(/';

        foreach ($documents as $readme => $contents) {
            if (preg_match($mention, $contents) !== 1) {
                $missing[] = "{$entry['class']}::{$entry['method']}() — {$readme}";
            }
        }
    }

    expect($missing)->toBe([], "Undocumented public methods:\n".implode("\n", $missing));
});

it('has a surface to check in the first place', function () {
    // Guards the guard: a reflection walk that quietly found nothing would make
    // the assertion above vacuously true.
    expect(count(PublicSurface::methods()))->toBeGreaterThan(200);
    expect(PublicSurface::classes())->toContain(Column::class);
});

it('reads a mention strictly enough to fail', function () use ($readmes) {
    // The same matcher, against a name no README contains. A `str_contains()`
    // that matched anything would let the whole suite pass on an empty file.
    foreach ($readmes as $readme) {
        $contents = (string) file_get_contents(__DIR__.'/../'.$readme);

        expect(preg_match('/\bneverDocumentedMethod\(/', $contents))->toBe(0);
        expect(preg_match('/\bpadStart\(/', $contents))->toBe(1);
    }
});

it('leaves the plumbing to the @internal tag rather than to the docs', function () {
    $surface = array_map(
        fn (array $entry): string => "{$entry['class']}::{$entry['method']}",
        PublicSurface::methods(),
    );

    // One of each exclusion, so a change that widens the surface by accident is
    // a failure here and not a silent extra documentation duty. Plain assertions
    // rather than `expect()->not`, which PHPStan cannot see at level `max`.
    Assert::assertNotContains('Column::resolve', $surface);              // marked `@internal`
    Assert::assertNotContains('Badge::type', $surface);                  // follows the abstract `CellConfig::type()`
    Assert::assertNotContains('InvalidDefinition::noColumns', $surface); // a named constructor, thrown and caught, never called
    Assert::assertNotContains('AuraServiceProvider::register', $surface); // Laravel's hook, not ours
    Assert::assertNotContains('Badge::currency', $surface);              // the trait's, counted on the trait

    Assert::assertContains('HasFormatting::currency', $surface);
    Assert::assertContains('Column::sortable', $surface);
    Assert::assertContains('AuraTable::respond', $surface);
});
