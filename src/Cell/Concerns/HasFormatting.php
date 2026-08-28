<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Cell\Concerns;

/**
 * The formatter chain Aura runs a rendered value through.
 *
 * The same block appears verbatim on six of the nine column configs — the
 * contract repeats it rather than referencing it — so it lives here once. The
 * order Aura applies it in is fixed and not the order of these calls: type
 * formatting (number / currency / date / phone) first, then the unit, then case
 * transforms, then slicing and padding.
 *
 * `datetime`, `time` and `raw` are absent from the config schemas but read by
 * the renderer all the same (`buildFormatConfig.ts`), and every config allows
 * additional properties — so they belong here beside the documented ones, and
 * a column that sets them can hand them down. The three the renderer reads and
 * nothing here offers — `currencyCode`, `sliceEnd`, `pad` — are reachable
 * through `merge()`.
 */
trait HasFormatting
{
    abstract public function set(string $key, mixed $value): static;

    /**
     * Format the value as a number, in the host app's locale.
     */
    public function number(bool $number = true): static
    {
        return $this->set('number', $number);
    }

    /**
     * Format the value as currency, using the host app's `currencyCode`.
     *
     * Worth knowing: Aura compares numerically only when both sides really are
     * numbers, and a Laravel `decimal` cast serialises as a string. A currency
     * column that also branches on `gt`/`lt` needs the numeric coercion the
     * table applies for it.
     */
    public function currency(bool $currency = true): static
    {
        return $this->set('currency', $currency);
    }

    /**
     * Format the value as a date.
     */
    public function date(bool $date = true): static
    {
        return $this->set('date', $date);
    }

    /**
     * Format the value as date + time.
     */
    public function datetime(bool $datetime = true): static
    {
        return $this->set('datetime', $datetime);
    }

    /**
     * Read the value as seconds and render it as `HH:mm:ss`.
     */
    public function time(bool $time = true): static
    {
        return $this->set('time', $time);
    }

    /**
     * Render the value as HTML. Aura sanitises it first, but the shortest safe
     * answer is still not to send markup you did not build.
     */
    public function raw(bool $raw = true): static
    {
        return $this->set('raw', $raw);
    }

    /**
     * Format the value as a phone number.
     */
    public function phone(bool $phone = true): static
    {
        return $this->set('phone', $phone);
    }

    /**
     * Unit appended to the formatted value, e.g. `kg` or `%`.
     */
    public function unit(string $unit): static
    {
        return $this->set('unit', $unit);
    }

    /**
     * Truncate the rendered text to this many characters.
     */
    public function slice(int $characters): static
    {
        return $this->set('slice', $characters);
    }

    /**
     * Upper-case the rendered text.
     */
    public function uppercase(bool $uppercase = true): static
    {
        return $this->set('uppercase', $uppercase);
    }

    /**
     * Lower-case the rendered text.
     */
    public function lowercase(bool $lowercase = true): static
    {
        return $this->set('lowercase', $lowercase);
    }

    /**
     * Capitalise the first letter of the rendered text.
     */
    public function capitalize(bool $capitalize = true): static
    {
        return $this->set('capitalize', $capitalize);
    }

    /**
     * Monospace font — pairs with `align('end')` on figures.
     */
    public function monospace(bool $monospace = true): static
    {
        return $this->set('monospace', $monospace);
    }

    /**
     * Pad the rendered text to this length on the left.
     */
    public function padStart(int $length, ?string $chars = null): static
    {
        $this->set('padStart', $length);

        return $chars === null ? $this : $this->set('chars', $chars);
    }

    /**
     * Pad the rendered text to this length on the right.
     */
    public function padEnd(int $length, ?string $chars = null): static
    {
        $this->set('padEnd', $length);

        return $chars === null ? $this : $this->set('chars', $chars);
    }

    /**
     * The character(s) padding uses. Usually passed to `padStart` / `padEnd`
     * directly.
     */
    public function chars(string $chars): static
    {
        return $this->set('chars', $chars);
    }
}
