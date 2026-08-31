<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Table;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Macroable;
use TamasLabs\Aura\Cell\CellConfig;
use TamasLabs\Aura\Cell\CellRules;
use TamasLabs\Aura\Contracts\AuraOption;
use TamasLabs\Aura\Exceptions\InvalidDefinition;

/**
 * One column of the table — which is to say one header cell.
 *
 * A column carries two kinds of setting, and the difference matters:
 *
 * - **Explicit**, set by calling a method here. These always win.
 * - **Inferred**, filled in from the model's casts by {@see Inference}. These
 *   only fill gaps, so adding a cast can never overwrite a deliberate choice,
 *   and `->withoutInference()` turns them off for one column.
 *
 * What the column allows is read back out of the emitted cell rather than
 * tracked separately: the field whitelist the query side enforces is derived
 * from the same array the browser receives, so the two cannot drift apart.
 */
final class Column
{
    use Macroable;

    /**
     * Set explicitly by the caller. Wins over everything inferred.
     *
     * @var array<string, mixed>
     */
    private array $attributes = [];

    /**
     * Derived — from the field name, or from the model's casts. Fills gaps only.
     *
     * @var array<string, mixed>
     */
    private array $inferred = [];

    private bool $infer = true;

    private bool $global = false;

    /** Built by {@see self::actions()}: the fields are routes, not data. */
    private bool $actions = false;

    /** @var string|list<string>|null */
    private string|array|null $cellClass = null;

    /** How this column's data cells are rendered — one entry of `body.columnConfigs`. */
    private ?CellConfig $config = null;

    /**
     * Per-field renderers for a multi-field column, keyed by field.
     *
     * @var array<string, CellConfig>
     */
    private array $fieldConfigs = [];

    /** Conditional styling of this column's `<td>`s. */
    private ?CellRules $rules = null;

    private function __construct() {}

    /**
     * A data column reading one field.
     *
     * The heading defaults to the field name in title case, so a column with an
     * obvious name is one call: `Column::make('last_name')`.
     */
    public static function make(string $field, ?string $content = null): self
    {
        $column = new self;
        $column->attributes['field'] = $field;
        $column->inferred['content'] = $content ?? self::titleFrom($field);

        if ($content !== null) {
            $column->attributes['content'] = $content;
        }

        return $column;
    }

    /**
     * A heading that names no field — the grouping cell of a two-row header.
     *
     * The contract requires such a cell to span at least two columns; prefer
     * {@see ColumnGroup} unless you are laying out a footer by hand.
     */
    public static function heading(?string $content, int $colspan = 2): self
    {
        $column = new self;
        $column->attributes['content'] = $content;
        $column->attributes['colspan'] = $colspan;

        return $column;
    }

    /**
     * The row-selection column.
     *
     * The field defaults to the model's primary key, because that is what Aura
     * sends back in `selected` — and it is the caller's bulk actions that then
     * have to find the rows again.
     */
    public static function selection(?string $field = null): self
    {
        $column = new self;
        $column->attributes['content'] = null;
        $column->attributes['selectable'] = true;

        if ($field !== null) {
            $column->attributes['field'] = $field;
        }

        return $column;
    }

    /**
     * A column assembled from several fields; Aura renders them together.
     *
     * Requires an explicit key (there is no single field to name it after), and
     * a {@see self::reference()} as soon as it is sortable or searchable.
     *
     * @param  list<string>  $fields
     */
    public static function combined(string $key, array $fields, ?string $content = null): self
    {
        $column = new self;
        $column->attributes['key'] = $key;
        $column->attributes['fields'] = $fields;
        $column->inferred['content'] = $content ?? self::titleFrom($key);

        if ($content !== null) {
            $column->attributes['content'] = $content;
        }

        return $column;
    }

    /**
     * The action column: Aura's built-in resource links, one field each.
     *
     * ```php
     * Column::actions('id', Action::show(), Action::edit(), Action::destroy())
     * ```
     *
     * `$key` is not a name this column chose. It is the **route placeholder**:
     * Aura writes it into the generated route (`{base}/{id}/edit`) and fills it
     * per row from the item field of the same name, so it has to be the
     * identifier the rows carry — normally the model's primary key. Two things
     * follow, and both are checked when the definition is built: no other
     * column may hold that key, and the field the placeholder names has to
     * reach the browser in the rows.
     *
     * Nothing is emitted into `body.columnConfigs`. The header states which
     * actions exist and the browser builds the rest, because the resource base
     * the routes hang off is client-side configuration (`urlParameter`) the
     * server never sees.
     *
     * @throws InvalidDefinition When no action is given.
     */
    public static function actions(string $key, Action ...$actions): self
    {
        if ($actions === []) {
            throw InvalidDefinition::noActions($key);
        }

        $column = new self;
        $column->actions = true;
        $column->attributes['content'] = null;
        $column->attributes['key'] = $key;
        $column->attributes['fields'] = array_map(
            static fn (Action $action): string => $action->field(),
            $actions,
        );

        return $column;
    }

    /**
     * Heading text. `null` renders an empty heading.
     */
    public function content(?string $content): self
    {
        return $this->set('content', $content);
    }

    /**
     * Unique column identifier. Defaults to the field.
     */
    public function key(string $key): self
    {
        return $this->set('key', $key);
    }

    /**
     * Offer sorting on this column. The field lands in the request's `sortable`.
     */
    public function sortable(bool $sortable = true): self
    {
        return $this->set('sortable', $sortable);
    }

    /**
     * Offer the per-column search input.
     */
    public function searchable(bool $searchable = true): self
    {
        return $this->set('searchable', $searchable);
    }

    /**
     * Offer the per-column filter dropdown.
     */
    public function filterable(bool $filterable = true): self
    {
        return $this->set('filterable', $filterable);
    }

    /**
     * Search this column with a min–max range instead of a term.
     *
     * Inferred for date and datetime casts; set it by hand for anything else
     * ordered, such as a plain integer column.
     */
    public function between(bool $between = true): self
    {
        return $this->set('between', $between);
    }

    /**
     * Send this field to the server instead of the column's own field.
     *
     * The way to sort a rendered column by an underlying one: a full-name column
     * sorts by `last_name`.
     */
    public function reference(string $field): self
    {
        return $this->set('reference', $field);
    }

    /**
     * Include this column in the toolbar's global search.
     *
     * Drives both halves at once: the field is listed in
     * `header.settings.searchableItems` for the browser, and allowed for global
     * search on the query side.
     */
    public function globalSearch(bool $global = true): self
    {
        $this->global = $global;

        return $this;
    }

    /**
     * The options offered by the filter dropdown.
     *
     * Inferred from a `BackedEnum` cast; pass them here for anything else.
     *
     * @param  array<string|int, string|int>|list<string|int>  $elements
     */
    public function elements(array $elements): self
    {
        return $this->set('elements', $elements);
    }

    /**
     * Filter options built from a backed enum, whether or not the model casts
     * to it. Labels come from {@see AuraOption}.
     *
     * @param  class-string<BackedEnum>  $enum
     */
    public function options(string $enum): self
    {
        return $this->elements(Inference::elementsFrom($enum));
    }

    /**
     * Initial column visibility. Hidden columns can still be turned on by the
     * user, so this is presentation, never authorisation.
     */
    public function show(bool $show = true): self
    {
        return $this->set('show', $show);
    }

    /**
     * Start hidden. See the warning on {@see self::show()}.
     */
    public function hidden(): self
    {
        return $this->show(false);
    }

    /**
     * Render row-selection checkboxes in this column.
     */
    public function selectable(bool $selectable = true): self
    {
        return $this->set('selectable', $selectable);
    }

    /**
     * Fixed column width — a CSS length such as `120px`, `20%` or `auto`.
     */
    public function width(string $width): self
    {
        return $this->set('width', $width);
    }

    /**
     * Let the user drag the column width.
     */
    public function resizable(bool $resizable = true): self
    {
        return $this->set('resizable', $resizable);
    }

    /**
     * Columns this cell spans.
     */
    public function colspan(int $colspan): self
    {
        return $this->set('colspan', $colspan);
    }

    /**
     * Rows this cell spans — set for you on an ungrouped column of a grouped
     * header.
     */
    public function rowspan(int $rowspan): self
    {
        return $this->set('rowspan', $rowspan);
    }

    /**
     * Text alignment inside the cell.
     *
     * @param  'start'|'center'|'end'  $align
     */
    public function align(string $align): self
    {
        return $this->set('align', $align);
    }

    /**
     * CSS classes on the heading cell.
     *
     * @param  string|list<string>  $class
     */
    public function class(string|array $class): self
    {
        return $this->set('class', $class);
    }

    /**
     * Inline CSS on the heading cell.
     */
    public function style(string $style): self
    {
        return $this->set('style', $style);
    }

    /**
     * CSS classes on this column's *data* cells — `body.columnStyles`.
     *
     * @param  string|list<string>  $class
     */
    public function cellClass(string|array $class): self
    {
        $this->cellClass = $class;

        return $this;
    }

    /**
     * Format the values as numbers.
     */
    public function number(bool $number = true): self
    {
        return $this->set('number', $number);
    }

    /**
     * Format the values as currency. Inferred from a `decimal` cast.
     */
    public function currency(bool $currency = true): self
    {
        return $this->set('currency', $currency);
    }

    /**
     * Format the values as dates. Inferred from a `date` cast.
     */
    public function date(bool $date = true): self
    {
        return $this->set('date', $date);
    }

    /**
     * Format the values as date + time. Inferred from a `datetime` cast.
     */
    public function datetime(bool $datetime = true): self
    {
        return $this->set('datetime', $datetime);
    }

    /**
     * Read the values as seconds and render them as `HH:mm:ss`.
     */
    public function time(bool $time = true): self
    {
        return $this->set('time', $time);
    }

    /**
     * Format the values as phone numbers.
     */
    public function phone(bool $phone = true): self
    {
        return $this->set('phone', $phone);
    }

    /**
     * Truncate the rendered text to this many characters.
     */
    public function slice(int $characters): self
    {
        return $this->set('slice', $characters);
    }

    /**
     * Upper-case the rendered values.
     */
    public function uppercase(bool $uppercase = true): self
    {
        return $this->set('uppercase', $uppercase);
    }

    /**
     * Lower-case the rendered values.
     */
    public function lowercase(bool $lowercase = true): self
    {
        return $this->set('lowercase', $lowercase);
    }

    /**
     * Capitalise the first letter of the rendered values.
     */
    public function capitalize(bool $capitalize = true): self
    {
        return $this->set('capitalize', $capitalize);
    }

    /**
     * Monospace heading font — worth pairing with `align('end')` on figures.
     */
    public function monospace(bool $monospace = true): self
    {
        return $this->set('monospace', $monospace);
    }

    /**
     * Render the values as HTML. Aura sanitises it before rendering, but the
     * shortest safe answer is still not to send markup you did not build.
     */
    public function raw(bool $raw = true): self
    {
        return $this->set('raw', $raw);
    }

    /**
     * How this column's data cells are rendered.
     *
     * ```php
     * Column::make('status')->filterable()->as(Badge::fromEnum(Status::class));
     * ```
     *
     * The configuration replaces the plain value the cell would otherwise show,
     * and Aura hands it to the renderer on its own — the heading's formatting
     * no longer reaches it. What the column already sets (`currency()`,
     * `date()`, `slice()` …) is therefore copied into the configuration as
     * defaults, so this call adds a rendering rather than quietly removing one.
     *
     * Only for a column that reads a single field. See
     * {@see self::configure()} for a multi-field one.
     */
    public function as(CellConfig $config): self
    {
        $this->config = $config;

        return $this;
    }

    /**
     * How one field of a multi-field column is rendered.
     *
     * Aura builds a segment per member field and looks each one up in
     * `columnConfigs` by that field's name, so a combined column is configured
     * a field at a time:
     *
     * ```php
     * Column::combined('name', ['first_name', 'last_name'])
     *     ->reference('last_name')
     *     ->configure('last_name', Reference::make()->uppercase());
     * ```
     */
    public function configure(string $field, CellConfig $config): self
    {
        $this->fieldConfigs[$field] = $config;

        return $this;
    }

    /**
     * Conditional styling of this column's `<td>` elements.
     *
     * Styling only, and per row — it cannot hide a row. A row the user must not
     * see belongs outside the query.
     */
    public function rules(CellRules $rules): self
    {
        $this->rules = $rules;

        return $this;
    }

    /**
     * Turn off model-driven inference for this column.
     */
    public function withoutInference(): self
    {
        $this->infer = false;

        return $this;
    }

    /**
     * Apply presets — reusable bundles of column settings.
     */
    public function apply(Preset ...$presets): self
    {
        foreach ($presets as $preset) {
            $preset->apply($this);
        }

        return $this;
    }

    /**
     * Set any header-cell key the contract defines, including `data-*`
     * attributes. The escape hatch for the keys with no method of their own.
     */
    public function set(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Set several keys at once.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function merge(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->set($key, $value);
        }

        return $this;
    }

    /**
     * Fill a key only if the caller has not set it — how {@see Inference} and
     * {@see Preset}s stay overridable.
     */
    public function default(string $key, mixed $value): self
    {
        if (! array_key_exists($key, $this->attributes)) {
            $this->inferred[$key] = $value;
        }

        return $this;
    }

    /**
     * The finished header cell.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidDefinition When the column cannot produce a valid cell.
     */
    public function resolve(?Model $model): array
    {
        $column = clone $this;

        if ($column->infer && $model instanceof Model) {
            Inference::apply($model, $column);
        }

        $cell = array_merge($column->inferred, $column->attributes);
        $cell = $column->withKey($cell);

        self::assertValid($cell);

        return $cell;
    }

    /**
     * Does this column belong to the global search?
     */
    public function wantsGlobalSearch(): bool
    {
        return $this->global;
    }

    /**
     * CSS classes for this column's data cells, if any.
     *
     * @return string|list<string>|null
     */
    public function resolvedCellClass(): string|array|null
    {
        return $this->cellClass;
    }

    /**
     * This column's cell renderer, if it has one.
     */
    public function cellConfig(): ?CellConfig
    {
        return $this->config;
    }

    /**
     * The per-field renderers of a multi-field column.
     *
     * @return array<string, CellConfig>
     */
    public function fieldConfigs(): array
    {
        return $this->fieldConfigs;
    }

    /**
     * This column's conditional cell styling, if any.
     */
    public function cellRules(): ?CellRules
    {
        return $this->rules;
    }

    /**
     * Is inference on for this column?
     */
    public function infers(): bool
    {
        return $this->infer;
    }

    /**
     * The field this column reads, before inference.
     */
    public function declaredField(): ?string
    {
        $field = $this->attributes['field'] ?? null;

        return is_string($field) ? $field : null;
    }

    /**
     * Does this column carry the row-selection checkboxes? Read by inference,
     * which supplies the model's key as the field to select on.
     */
    public function isSelectable(): bool
    {
        return (bool) ($this->attributes['selectable'] ?? false);
    }

    /**
     * Was this column built by {@see self::actions()}?
     *
     * The distinction is not visible in the emitted cell — an action column is
     * a multi-field cell like any other — but the guards need it: an action
     * field is only allowed here, and this column's key is a route placeholder
     * rather than a name that can be changed.
     */
    public function isActionColumn(): bool
    {
        return $this->actions;
    }

    /**
     * Is this column searchable? Read by inference, which only offers a range
     * input on a column that has a search input at all.
     */
    public function isSearchable(): bool
    {
        return (bool) ($this->attributes['searchable'] ?? false);
    }

    /**
     * A readable heading from a field name: `company.name` becomes
     * "Company Name". Only ever a default — a real table names its own columns,
     * usually through a translation.
     */
    private static function titleFrom(string $field): string
    {
        return Str::headline(str_replace('.', ' ', $field));
    }

    /**
     * Emit `key` explicitly rather than leaning on Aura's own default, so the
     * key the whitelist is built from is the key the browser sees.
     *
     * @param  array<string, mixed>  $cell
     * @return array<string, mixed>
     */
    private function withKey(array $cell): array
    {
        if (isset($cell['key']) && is_string($cell['key'])) {
            return $cell;
        }

        if (isset($cell['field']) && is_string($cell['field'])) {
            $cell['key'] = $cell['field'];
        }

        return $cell;
    }

    /**
     * The structural rules the header schema states about a cell, checked here
     * so a broken definition fails on the server rather than rendering wrongly
     * in the browser.
     *
     * Four of them are the `headerCell` rules themselves; the fifth is the
     * `minItems: 1` on `fields`, which is the same class of mistake — a cell
     * naming no field at all is not a column to Aura (`TableBody.tsx` wants
     * `fields.length > 0`), and it fails Aura's own response validation, which
     * takes the whole table down rather than the one column.
     *
     * @param  array<string, mixed>  $cell
     *
     * @throws InvalidDefinition
     */
    private static function assertValid(array $cell): void
    {
        $content = isset($cell['content']) && is_string($cell['content']) ? $cell['content'] : null;
        $hasField = isset($cell['field']) && is_string($cell['field']);
        $rawKey = $cell['key'] ?? null;
        $hasKey = is_string($rawKey);
        $key = is_string($rawKey) ? $rawKey : '';

        if ($content === '') {
            throw InvalidDefinition::emptyHeading($key);
        }

        $hasFields = isset($cell['fields']) && is_array($cell['fields']);

        // Checked in the schema's own order: mutual exclusion first, then the
        // key `fields` requires, then the colspan a grouping cell requires.
        if ($hasField && $hasFields) {
            throw InvalidDefinition::fieldAndFields($key);
        }

        if ($hasFields && ! $hasKey) {
            throw InvalidDefinition::missingField($content);
        }

        if ($hasFields && $cell['fields'] === []) {
            throw InvalidDefinition::emptyFields($key);
        }

        if ($hasField || $hasFields) {
            return;
        }

        $colspan = $cell['colspan'] ?? null;

        if (! is_int($colspan) || $colspan < 2) {
            throw InvalidDefinition::unspannedHeading($content);
        }
    }
}
