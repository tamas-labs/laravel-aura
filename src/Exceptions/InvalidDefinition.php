<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Exceptions;

use LogicException;

/**
 * Raised while a table definition is being built, when the definition itself is
 * wrong — a column that cannot produce a valid header cell, or two columns
 * fighting over one key.
 *
 * Every one of these is a programming mistake in the table class, discovered on
 * the first request that touches it rather than by the browser silently
 * rendering the wrong thing. `LogicException` for exactly that reason: nothing
 * a user does can cause one.
 */
final class InvalidDefinition extends LogicException implements AuraException
{
    /**
     * The response is keyed by column; two columns cannot share one key, or the
     * second silently wins in `columnConfigs`, `columnStyles` and the session
     * state Aura persists per column.
     */
    public static function duplicateKey(string $key): self
    {
        return new self(sprintf(
            'Two columns share the key "%s". A column key identifies the column in body.columnConfigs, '
            .'in body.columnStyles and in Aura\'s per-column session state, so it has to be unique. '
            .'Give one of them an explicit key().',
            $key,
        ));
    }

    /**
     * The contract requires at least one header row with at least one cell.
     */
    public static function noColumns(string $table): self
    {
        return new self(sprintf('%s::columns() returned nothing; a table needs at least one column.', $table));
    }

    /**
     * `fields` has no single name to send, so the request would carry nothing
     * the server could sort or search on.
     */
    public static function multiFieldNeedsReference(string $key, string $operation): self
    {
        return new self(sprintf(
            'Column "%s" is %s but reads several fields, so there is no single field name to send. '
            .'Name the one the server should use with ->reference(\'…\').',
            $key,
            $operation,
        ));
    }

    /**
     * `header.settings.searchableItems` is matched against the `field` of a
     * header cell, and a multi-field column has no `field`.
     */
    public static function multiFieldInGlobalSearch(string $key): self
    {
        return new self(sprintf(
            'Column "%s" reads several fields, so it cannot join the global search: '
            .'header.settings.searchableItems names the field of a header cell, and this column has none. '
            .'Put the individual columns in the global search instead.',
            $key,
        ));
    }

    /**
     * A cell with neither `field` nor `fields` is a grouping cell, and the
     * schema requires those to span at least two columns.
     */
    public static function unspannedHeading(?string $content): self
    {
        return new self(sprintf(
            'The heading cell %s names no field, which makes it a grouping cell — and a grouping cell has to '
            .'span at least two columns. Give it a field, or a colspan of 2 or more.',
            $content === null ? '(with no content)' : '"'.$content.'"',
        ));
    }

    /**
     * A group of one is a column with a title, and would emit `colspan: 1` on a
     * field-less cell, which the schema rejects.
     */
    public static function emptyGroup(string $content, int $size): self
    {
        return new self(sprintf(
            'The column group "%s" holds %d column(s); a group has to span at least two, '
            .'or it is just a column with a heading.',
            $content,
            $size,
        ));
    }

    /**
     * A column has to name its source, unless it is a grouping cell.
     */
    public static function missingField(?string $content): self
    {
        return new self(sprintf(
            'The column %s has no field, no fields and no key, so nothing identifies it. '
            .'Pass a field to Column::make(), or use Column::heading() for a grouping cell.',
            $content === null ? '(with no content)' : '"'.$content.'"',
        ));
    }
}
