<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Tests\Docs;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;

/**
 * The package's public surface, as reflection sees it.
 *
 * "Public" here means what a host application may call and what the version
 * promise therefore covers — which is narrower than `public function`. Four
 * kinds of method are excluded, and each exclusion is a claim the test suite
 * makes about the code:
 *
 * - anything marked `@internal`, on the method or on its class;
 * - anything overriding a method an `@internal` Aura ancestor declares, so the
 *   nine `type()` implementations follow the abstract one that names them;
 * - a method a framework class or interface asked for (`register()`, `handle()`),
 *   which answers to Laravel, not to a reader of these docs;
 * - a method whose code lives in another file — a trait's, counted once on the
 *   trait itself rather than once per class using it.
 *
 * @internal
 */
final class PublicSurface
{
    /** Where the package's own code lives. */
    public const ROOT = __DIR__.'/../../src';

    /**
     * Every public method the documentation has to cover.
     *
     * @return list<array{class: string, method: string}>
     */
    public static function methods(): array
    {
        $surface = [];

        foreach (self::classes() as $class) {
            $reflection = new ReflectionClass($class);

            if (self::hasInternalTag($reflection->getDocComment())) {
                continue;
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (self::isExcluded($reflection, $method)) {
                    continue;
                }

                $surface[] = ['class' => $reflection->getShortName(), 'method' => $method->getName()];
            }
        }

        return $surface;
    }

    /**
     * Every class, interface and trait under `src/`, by PSR-4 name.
     *
     * @return list<class-string>
     */
    public static function classes(): array
    {
        $root = (string) realpath(self::ROOT);
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        $classes = [];

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            $name = 'TamasLabs\\Aura\\'.str_replace(['/', '.php'], ['\\', ''], $relative);

            if (class_exists($name) || interface_exists($name) || trait_exists($name)) {
                $classes[] = $name;
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * Is this method one the documentation does not have to mention?
     *
     * @param  ReflectionClass<object>  $class
     */
    private static function isExcluded(ReflectionClass $class, ReflectionMethod $method): bool
    {
        if (str_starts_with($method->getName(), '__')) {
            return true;
        }

        // A trait's method, reached through a class using it. The trait is
        // scanned in its own right, so the method is still covered once.
        if ($method->getFileName() !== $class->getFileName()) {
            return true;
        }

        return self::hasInternalTag($method->getDocComment())
            || self::inheritsInternal($class, $method->getName())
            || self::isFrameworkHook($class, $method->getName());
    }

    /**
     * Does an Aura ancestor mark this method — or itself — `@internal`?
     *
     * @param  ReflectionClass<object>  $class
     */
    private static function inheritsInternal(ReflectionClass $class, string $method): bool
    {
        foreach (self::ancestors($class) as $ancestor) {
            if (! str_starts_with($ancestor, 'TamasLabs\\Aura\\')) {
                continue;
            }

            $reflection = new ReflectionClass($ancestor);

            if (self::hasInternalTag($reflection->getDocComment())) {
                return true;
            }

            if ($reflection->hasMethod($method)
                && self::hasInternalTag($reflection->getMethod($method)->getDocComment())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this a method a framework class or interface asked for? Laravel reads
     * `register()`, `boot()` and `handle()`; a reader of these docs never calls
     * one.
     *
     * @param  ReflectionClass<object>  $class
     */
    private static function isFrameworkHook(ReflectionClass $class, string $method): bool
    {
        foreach (self::ancestors($class) as $ancestor) {
            if (str_starts_with($ancestor, 'TamasLabs\\Aura\\')) {
                continue;
            }

            if (method_exists($ancestor, $method)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parent classes and implemented interfaces, nearest first.
     *
     * @param  ReflectionClass<object>  $class
     * @return list<class-string>
     */
    private static function ancestors(ReflectionClass $class): array
    {
        return array_values(array_merge(
            class_parents($class->getName()) ?: [],
            class_implements($class->getName()) ?: [],
        ));
    }

    /**
     * PHP hands back `false` for a declaration with no docblock at all.
     */
    private static function hasInternalTag(string|false $docBlock): bool
    {
        return is_string($docBlock) && str_contains($docBlock, '@internal');
    }
}
