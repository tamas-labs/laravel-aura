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
     * A cell configuration sets content, not shape. `type` decides which
     * renderer reads the rest, and `key` / `if` / `else` are how the conditions
     * are found — a hand-written one wins over the emitted one and takes the
     * conditions with it, silently.
     */
    public static function rawStructuralKey(string $key): self
    {
        return new self(sprintf(
            'A cell configuration cannot set "%s" directly: it decides how the rest of the '
            .'configuration is read, and would override what the builder emits. Use the type\'s own '
            .'factory for "type", and on() / when() / otherwise() for the conditions.',
            $key,
        ));
    }

    /**
     * Aura stops resolving at `MAX_RECURSION_DEPTH` and renders the truncated
     * configuration — reporting it to its error store and nowhere the user
     * looks. A definition that would hit the cap is a mistake worth making
     * loud.
     */
    public static function conditionsTooDeep(int $depth, int $max): self
    {
        return new self(sprintf(
            'The conditions nest %d levels deep; Aura resolves %d and silently drops the rest. '
            .'Flatten the branches, or move the decision into the query.',
            $depth,
            $max,
        ));
    }

    /**
     * Without a string `key`, Aura skips the conditions and applies the base
     * configuration (`resolve-conditional-config.ts`). Fail-open, and the wrong
     * direction whenever the condition decides whether something is shown.
     */
    public static function conditionsWithoutKey(): self
    {
        return new self(
            'A conditional cell configuration needs the field its conditions read, and nothing '
            .'supplied one. Name it with ->on(\'field\'); without it Aura ignores the conditions '
            .'and applies the unconditional configuration instead.',
        );
    }

    /**
     * Every column config requires one of a handful of keys, or it renders
     * nothing at all.
     *
     * @param  list<list<string>>  $alternatives
     */
    public static function incompleteCellConfig(string $type, array $alternatives): self
    {
        $spelled = array_map(
            static fn (array $keys): string => implode(' + ', $keys),
            $alternatives,
        );

        return new self(sprintf(
            'A "%s" cell configuration needs one of: %s. Without one it renders an empty cell.',
            $type,
            implode(', ', $spelled),
        ));
    }

    /**
     * Aura resolves a route by replacing every dot with a slash, so an absolute
     * URL comes out the other end as a path — `https://app.test/users/5` turns
     * into `/https://app/test/users/5`.
     */
    public static function absoluteRoute(string $route): self
    {
        return new self(sprintf(
            'The route "%s" is absolute, and Aura only builds relative paths: it replaces every dot '
            .'with a slash and prefixes the host app\'s siteName. Pass a path such as "users.{id}.edit", '
            .'not the output of route().',
            $route,
        ));
    }

    /**
     * Aura substitutes `\{([\w.]+)\}` and nothing else; a placeholder outside
     * that alphabet survives into the URL as literal text.
     */
    public static function unresolvablePlaceholder(string $route, string $placeholder): self
    {
        return new self(sprintf(
            'The route "%s" has a placeholder "{%s}" Aura will not substitute — it matches only '
            .'letters, digits, underscores and dots, and leaves anything else in the URL verbatim.',
            $route,
            $placeholder,
        ));
    }

    /**
     * `columnConfigs` is read by field for the renderer and by key for
     * `cellRules`; a column whose two differ would need both entries, and would
     * quietly get whichever one the lookup happened to reach.
     */
    public static function configNeedsMatchingKey(string $key, ?string $field): self
    {
        return new self(sprintf(
            'Column "%s" carries a cell configuration but its key and field differ (field: %s). '
            .'Aura looks the renderer up under the field and cellRules under the key, so only half '
            .'of it would ever be read. Drop the explicit key(), or configure the column by field.',
            $key,
            $field === null ? 'none' : '"'.$field.'"',
        ));
    }

    /**
     * `columnConfigs` is one flat map keyed by field. A second configuration
     * for the same field overwrites the first rather than joining it, and the
     * column that lost renders the winner's configuration without a word.
     */
    public static function conflictingCellConfig(string $field, string $key): self
    {
        return new self(sprintf(
            'Field "%s" already has a cell configuration, and column "%s" gives it another. '
            .'Aura keys columnConfigs by field, so the second would simply replace the first. '
            .'Render the field in one column, or give the other column a field of its own.',
            $field,
            $key,
        ));
    }

    /**
     * A multi-field column has no single field to key a configuration by: Aura
     * builds one segment per member field and looks each one up by name.
     */
    public static function configOnMultiFieldColumn(string $key): self
    {
        return new self(sprintf(
            'Column "%s" reads several fields, so a single cell configuration has nowhere to attach: '
            .'Aura renders one segment per field and looks each up by that field\'s name. '
            .'Configure the members with ->configure(\'field\', …) instead.',
            $key,
        ));
    }

    /**
     * A member of `fields` is the only thing `configure()` can name.
     *
     * @param  list<string>  $fields
     */
    public static function configureUnknownField(string $key, string $field, array $fields): self
    {
        return new self(sprintf(
            'Column "%s" has no field "%s" to configure; it reads %s.',
            $key,
            $field,
            $fields === [] ? 'no fields' : '"'.implode('", "', $fields).'"',
        ));
    }

    /**
     * The contract types a heading as a non-empty string or `null`; an empty
     * one fails Aura's own response validation, which takes the whole table
     * down rather than the one cell.
     */
    public static function emptyHeading(string $key): self
    {
        return new self(sprintf(
            'The heading of column "%s" is an empty string, which the contract does not allow. '
            .'Pass null for a deliberately blank heading — an action column, say.',
            $key,
        ));
    }

    /**
     * The header schema states four structural rules about a cell, and this is
     * the fourth: `field` and `fields` are mutually exclusive
     * (`not: {required: [field, fields]}`). Reachable only through the `set()`
     * escape hatch, which is exactly why it is checked — a cell carrying both
     * fails Aura's own response validation, which takes the whole table down
     * rather than the one column.
     */
    public static function fieldAndFields(string $key): self
    {
        return new self(sprintf(
            'Column "%s" names both a field and a fields list, and the contract allows only one: '
            .'a cell reads its value from "field" or from "fields", never from both. '
            .'Use Column::make() for a single field and Column::combined() for several.',
            $key,
        ));
    }

    /**
     * Cell rules on a multi-field column have no field to fall back on.
     *
     * The rules are keyed by the column key, and for a multi-field column that
     * key is a name the rows have never heard of — so Aura reads `undefined`,
     * every condition is false, and nothing is ever styled. Silently.
     */
    public static function rulesNeedField(string $key): self
    {
        return new self(sprintf(
            'Column "%s" reads several fields, so its cell rules have no single field to read. '
            .'Aura would evaluate the conditions against "%s", which is a column key and not a '
            .'value in the row: every condition would be false and nothing would ever be styled. '
            .'Name the field the rules read with ->on(\'field\').',
            $key,
            $key,
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
