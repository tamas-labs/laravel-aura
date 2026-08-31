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
     * An action column with no actions is a heading with nothing under it, and
     * `fields: []` fails the schema's own `minItems: 1`.
     */
    public static function noActions(string $key): self
    {
        return new self(sprintf(
            'Column::actions(\'%s\') was given no actions. Name at least one — Action::show(), '
            .'Action::edit(), Action::create() or Action::destroy() — or leave the column out.',
            $key,
        ));
    }

    /**
     * `fields` is typed `minItems: 1`, and Aura only treats a cell as a column
     * when it names something (`TableBody.tsx`: `cell.field || cell.fields.length`).
     */
    public static function emptyFields(string $key): self
    {
        return new self(sprintf(
            'Column "%s" names an empty fields list. A multi-field column has to read at least one '
            .'field; Aura would not treat this cell as a column at all, and the contract rejects it.',
            $key,
        ));
    }

    /**
     * The action column's key is the route placeholder Aura substitutes per
     * row, so it is not a free choice — the other column is the one that can
     * move.
     */
    public static function actionKeyTaken(string $key, bool $selection = false): self
    {
        // The selection column is the collision almost every table hits: its
        // key defaults to the model's primary key, which is exactly the
        // placeholder an action column needs. Say what is safe to change.
        $advice = $selection
            ? 'Re-key the selection column — Column::selection()->key(\'select\') — which changes nothing '
                .'about the selection itself: Aura reads the row id from that column\'s field '
                .'(resolve-row-id.ts), never from its key.'
            : 'Give the other column an explicit key() instead — a key only identifies the column in the '
                .'payload, and sorting and searching still travel by field.';

        return new self(sprintf(
            'The action column keys on "%s", and another column already uses that key. An action '
            .'column\'s key is not a name: Aura writes it into the generated route as the placeholder '
            .'({base}/{%s}/edit) and fills it from the item field of the same name, so it has to stay '
            .'the identifier. %s',
            $key,
            $key,
            $advice,
        ));
    }

    /**
     * Sorting, searching or filtering a column of links asks the server to
     * operate on a field that exists nowhere but the URL.
     */
    public static function actionColumnOperable(string $key, string $operation): self
    {
        $verb = match ($operation) {
            'sortable' => 'sort by',
            'searchable' => 'search',
            'filterable' => 'filter by',
            default => 'search globally',
        };

        return new self(sprintf(
            'The action column "%s" is marked %s, but its fields are routes rather than data — there '
            .'is no column behind them to %s. Put the flag on the column that shows the value.',
            $key,
            $operation,
            $verb,
        ));
    }

    /**
     * A resource-action field name outside an action column: Aura would build
     * a route for it against whatever key that cell happens to carry.
     */
    public static function actionFieldOutsideActionColumn(string $key, string $field): self
    {
        return new self(sprintf(
            'Column "%s" names the field "%s", which Aura reads as a built-in resource action and '
            .'turns into a route of its own. Declare it with Column::actions(), which states the '
            .'route placeholder explicitly — or rename the field if it really is data.',
            $key,
            $field,
        ));
    }

    /**
     * `columnConfigs` is a flat map keyed by field, so the second occurrence of
     * an action does not get a second entry — it gets the first one's, built
     * with the first cell's key.
     */
    public static function duplicateAction(string $field, string $first, string $second): self
    {
        return new self(sprintf(
            'The action "%s" appears in both column "%s" and column "%s". Aura generates one '
            .'configuration per field name, so the second column would silently render the first '
            .'column\'s route — built with the first column\'s key. Offer the action once.',
            $field,
            $first,
            $second,
        ));
    }

    /**
     * An escalated action builds its own route, and the resource convention is
     * where the server-side half of it comes from.
     */
    public static function actionNeedsResource(string $field): self
    {
        return new self(sprintf(
            'The action "%s" is customised, so it has to emit its own configuration — and that '
            .'configuration needs a route the server can build. Set $resource on the table '
            .'(protected ?string $resource = \'admin/users\';), or give this action a route of its '
            .'own with ->route(\'…\') or ->routeName(\'…\'). In convention mode no route is needed: '
            .'the browser builds it from its own urlParameter.',
            $field,
        ));
    }

    /**
     * Aura turns every dot into a slash, so a route name passed where a path
     * belongs resolves to a real URL with the identifier missing.
     */
    public static function dottedActionRoute(string $route, ?string $name = null): self
    {
        $source = $name === null
            ? 'Pass a path such as "users/{id}/edit"'
            : sprintf('The route "%s" is registered with that path; give it one without a dot', $name);

        return new self(sprintf(
            'The action route "%s" contains a dot, and Aura turns every dot into a slash — so this '
            .'would resolve to a real URL that is not the one you meant, without an error anywhere. '
            .'%s, or name a route with ->routeName(\'…\').',
            $route,
            $source,
        ));
    }

    /**
     * The resource base is prefixed onto every generated action route.
     */
    public static function invalidResource(string $resource, string $fault): self
    {
        $why = $fault === 'absolute'
            ? 'Aura only builds relative paths and prefixes the host app\'s siteName itself'
            : 'Aura turns every dot into a slash, so the base would silently split into extra segments';

        return new self(sprintf(
            'The table\'s $resource "%s" cannot be used as a route base: %s. Give it a plain '
            .'relative path such as "admin/users".',
            $resource,
            $why,
        ));
    }

    /**
     * A route name that resolves to nothing would leave the action pointing at
     * an empty path.
     */
    public static function unknownRoute(string $name): self
    {
        return new self(sprintf(
            'No route is named "%s". Check the name against `php artisan route:list`, and remember '
            .'that a table definition may be built before the routes it points at are registered — '
            .'in that case name the path directly with ->route(\'…\').',
            $name,
        ));
    }

    /**
     * Exactly one parameter may be left for Aura to fill from the row: the one
     * the action column keys on.
     *
     * @param  list<string>  $open
     */
    public static function ambiguousRoute(string $name, array $open): self
    {
        return new self(sprintf(
            'The route "%s" leaves %d parameters open (%s), and only one can be filled from the row '
            .'— the one the action column keys on. Pass the others to routeName(): '
            .'->routeName(\'%s\', [\'%s\' => $value]).',
            $name,
            count($open),
            '{'.implode('}, {', $open).'}',
            $name,
            $open[0] ?? 'parameter',
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
