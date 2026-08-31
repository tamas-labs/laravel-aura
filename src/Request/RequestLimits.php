<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Request;

/**
 * The ceilings one request is held to.
 *
 * Everything in an Aura request arrives from the browser, and for a long time
 * `paginate` was the only part of it with a ceiling. A search term, a selection
 * and the values of one filter had none — and neither did the three lists, so a
 * request carrying five thousand sorts built a hundred and twenty kilobytes of
 * SQL before the database saw a row.
 *
 * **What is not here is the point.** The `sortable`, `searchable` and
 * `filterable` lists need no configured ceiling, because an exact one already
 * exists. Aura keeps a single entry per field — `use-sorting.ts:23`,
 * `use-searching.ts:45` and `use-filtering.ts:41` all look the field up before
 * pushing — so none of those lists can be longer than the field whitelist it
 * draws from: a table offering three sortable columns accepts three sorts and
 * no more. Deriving that is tighter than any number picked here, needs no
 * config key, and cannot go stale when the columns change.
 *
 * Every argument is an override, and anything left `null` comes from
 * `config('aura.*')`. A missing or non-positive configured value falls back to
 * the constant beside it rather than to "no limit": a limit a broken config can
 * switch off is not a limit.
 */
final readonly class RequestLimits
{
    /** Items per page. Clamped rather than rejected — see {@see AuraRequest}. */
    public const int PAGINATE = 100;

    /** Ids in `selected`. The one list the client can grow without bound. */
    public const int SELECTED = 1000;

    /** Values in one `filterable[].values`. */
    public const int VALUES = 200;

    /** Characters in `globalSearch` and in a `searchable[].term`. */
    public const int TERM = 255;

    public int $paginate;

    public int $selected;

    public int $values;

    public int $term;

    public function __construct(
        ?int $paginate = null,
        ?int $selected = null,
        ?int $values = null,
        ?int $term = null,
    ) {
        $this->paginate = self::limit($paginate, 'pagination.max', self::PAGINATE);
        $this->selected = self::limit($selected, 'limits.selected', self::SELECTED);
        $this->values = self::limit($values, 'limits.values', self::VALUES);
        $this->term = self::limit($term, 'limits.term', self::TERM);
    }

    /**
     * The configured limits, with nothing overridden.
     */
    public static function fromConfig(): self
    {
        return new self;
    }

    /**
     * One limit: the caller's override, or the configured value, or the
     * packaged default. There is no spelling for "unlimited" on purpose.
     */
    private static function limit(?int $override, string $key, int $default): int
    {
        return $override === null ? self::configured($key, $default) : max(1, $override);
    }

    /**
     * One `aura.*` integer, or the packaged default when it is missing or
     * cannot be read as a positive number.
     */
    private static function configured(string $key, int $default): int
    {
        $value = config('aura.'.$key);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
