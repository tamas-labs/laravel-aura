<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Response;

/**
 * Makes the fields Aura compares numerically arrive as numbers.
 *
 * Aura's `gt` / `gte` / `lt` / `lte` / `between` operators require
 * `typeof === 'number'` on both sides — or both sides to parse as dates —
 * and return **false** otherwise, with nothing logged anywhere
 * (`evaluate-condition.ts`, `evaluateDateOrNumber`). Laravel's `decimal:2`
 * cast serialises as a string: `{"balance":"1234.50"}`. So a condition like
 * `Condition::gt(1000)` on a money column silently never matches, on exactly
 * the sort of column that most wants one.
 *
 * The columns say which fields they compare numerically, and those — and only
 * those — are converted here. Two deliberate limits:
 *
 * - **Only numeric strings are touched.** A date field compared with `gt` is
 *   legitimate, Aura parses it as a date, and `(float) '2024-01-01'` would be
 *   `2024.0`. `is_numeric()` is what separates the two.
 * - **Only fields a condition actually reads.** Casting every decimal in the
 *   payload would be a wider change than the problem, and would drop the
 *   trailing zeros a caller may be relying on elsewhere.
 *
 * @internal
 */
final class NumericFields
{
    /**
     * @param  list<mixed>  $items
     * @param  list<string>  $fields  Dot paths, as they appear in a column's `field`.
     * @return list<mixed>
     */
    public static function coerce(array $items, array $fields): array
    {
        if ($fields === []) {
            return $items;
        }

        return array_map(
            static function (mixed $item) use ($fields): mixed {
                if (! is_array($item)) {
                    return $item;
                }

                foreach ($fields as $field) {
                    $item = self::coercePath($item, explode('.', $field));
                }

                return $item;
            },
            $items,
        );
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @param  list<string>  $path
     * @return array<array-key, mixed>
     */
    private static function coercePath(array $item, array $path): array
    {
        $step = array_shift($path);

        if ($step === null || ! array_key_exists($step, $item)) {
            return $item;
        }

        if ($path === []) {
            $item[$step] = self::number($item[$step]);

            return $item;
        }

        $nested = $item[$step];

        if (is_array($nested)) {
            $item[$step] = self::coercePath($nested, $path);
        }

        return $item;
    }

    /**
     * A numeric string as a number; anything else untouched.
     */
    private static function number(mixed $value): mixed
    {
        if (! is_string($value) || ! is_numeric($value)) {
            return $value;
        }

        $float = (float) $value;

        // An integral value goes back as an int so it serialises without a
        // pointless `.0`, unless it is too large to survive the round trip.
        return $float === floor($float) && abs($float) < (float) PHP_INT_MAX
            ? (int) $float
            : $float;
    }
}
