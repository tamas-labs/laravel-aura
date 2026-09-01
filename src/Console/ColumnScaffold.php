<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Console;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use TamasLabs\Aura\Table\Inference;
use Throwable;

/**
 * Turns a model into the body of a generated `columns()` method.
 *
 * The source of truth is the **database**, read once through
 * `Schema::getColumns()`, crossed with the model's casts. A generator that
 * guessed from property names would be wrong exactly where it matters — an
 * enum column is only recognisable from the cast, and a `decimal` from the
 * schema.
 *
 * Nothing here is inference in the {@see Inference}
 * sense: this writes source code a person then edits, and every line it emits
 * is one they can delete. It aims to be a defensible first draft, not a final
 * answer — so it never guesses a heading (the column derives one), never picks
 * a global-search field (that is an editorial choice), and leaves a comment
 * wherever it declined to decide.
 *
 * @internal
 */
final class ColumnScaffold
{
    /** Column types that have no sensible default rendering in a table. */
    private const OPAQUE = ['json', 'jsonb', 'blob', 'bytea', 'binary', 'varbinary', 'geometry'];

    /** Casts that make a column opaque whatever the schema says. */
    private const OPAQUE_CASTS = ['array', 'json', 'object', 'collection', 'encrypted'];

    /** One indent level in the generated file, matching Pint's `laravel` preset. */
    private const INDENT = '            ';

    private function __construct(
        private readonly Model $model,
        /** @var list<array{name: string, type: string}> */
        private readonly array $columns,
    ) {}

    /**
     * Read the model's table, or come back empty when the database cannot be
     * reached — a generator that fails because nothing is migrated yet would be
     * useless exactly when a table is being scaffolded.
     */
    public static function read(Model $model): self
    {
        return new self($model, self::describe($model));
    }

    /**
     * Did the database answer?
     */
    public function isEmpty(): bool
    {
        return $this->columns === [];
    }

    /**
     * How many data columns were written.
     */
    public function count(): int
    {
        return count($this->dataColumns());
    }

    /**
     * The `columns()` body, indented and ready to drop into the stub.
     */
    public function render(): string
    {
        $lines = [
            '// Aura reads the row id from this column\'s field, not its key, so',
            '// re-keying it is free — and it has to be re-keyed, because the',
            '// action column below needs "'.$this->model->getKeyName().'" as its route placeholder.',
            'Column::selection()->key(\'select\'),',
            '',
        ];

        foreach ($this->lines() as $line) {
            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = 'Column::actions(\''.$this->model->getKeyName().'\', Action::show(), Action::edit(), Action::destroy()),';

        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? '' : self::INDENT.$line,
            $lines,
        ));
    }

    /**
     * One entry per table column: a `Column::make()` call, a comment saying why
     * there is none, or nothing at all.
     *
     * @return list<string>
     */
    private function lines(): array
    {
        if ($this->columns === []) {
            return [
                '// The database could not be read, so there is nothing to scaffold from.',
                '// Column::make(\'name\')->sortable()->searchable(),',
            ];
        }

        $lines = [];

        foreach ($this->columns as $column) {
            foreach ($this->line($column['name'], $column['type']) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * The data columns actually emitted, for the command's summary line.
     *
     * @return list<string>
     */
    private function dataColumns(): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $column): ?string => $this->flagsFor($column['name'], $column['type']) === null
                    ? null
                    : $column['name'],
                $this->columns,
            ),
            is_string(...),
        ));
    }

    /**
     * @return list<string>
     */
    private function line(string $name, string $type): array
    {
        if ($name === $this->model->getKeyName()) {
            // The selection column already reads it, and the action column
            // already keys on it.
            return [];
        }

        if (in_array($name, $this->model->getHidden(), true)) {
            return [];
        }

        if (str_ends_with($name, '_id')) {
            return [
                '// '.$name.' is a foreign key. Column::make(\''.substr($name, 0, -3).'.name\') renders the',
                '// related row instead, and sorts it with a correlated subquery.',
                '',
            ];
        }

        $flags = $this->flagsFor($name, $type);

        if ($flags === null) {
            return [
                '// '.$name.' has no default rendering — give it a cell configuration, or leave it out.',
                '',
            ];
        }

        return ['Column::make(\''.$name.'\')'.$flags.','];
    }

    /**
     * The builder calls for one column, or `null` when the type has no sensible
     * default.
     */
    private function flagsFor(string $name, string $type): ?string
    {
        if ($name === $this->model->getKeyName() || in_array($name, $this->model->getHidden(), true)) {
            return null;
        }

        if (str_ends_with($name, '_id')) {
            return null;
        }

        $cast = $this->castOf($name);

        if ($cast !== null && in_array($cast, self::OPAQUE_CASTS, true)) {
            return null;
        }

        if ($cast !== null && is_a($cast, BackedEnum::class, true)) {
            // The cast fills the filter dropdown's `elements` on its own.
            return '->filterable()';
        }

        if ($cast === 'boolean' || $cast === 'bool') {
            return '->filterable()';
        }

        foreach (self::OPAQUE as $opaque) {
            if (str_contains($type, $opaque)) {
                return null;
            }
        }

        if (str_contains($type, 'bool') || $type === 'tinyint(1)') {
            return '->filterable()';
        }

        // Everything left — text, numbers and dates — reads and searches the
        // same way. What separates them is inference, from the cast, at build
        // time: a decimal gains `currency`, a datetime gains a range input.
        return '->sortable()->searchable()';
    }

    /**
     * The model's cast for a column, with any `decimal:2`-style argument cut.
     */
    private function castOf(string $name): ?string
    {
        $cast = $this->model->getCasts()[$name] ?? null;

        if (! is_string($cast)) {
            return null;
        }

        $position = strpos($cast, ':');

        return $position === false ? $cast : substr($cast, 0, $position);
    }

    /**
     * @return list<array{name: string, type: string}>
     */
    private static function describe(Model $model): array
    {
        try {
            $columns = Schema::connection($model->getConnectionName())->getColumns($model->getTable());
        } catch (Throwable) {
            // No connection, no table, an unsupported driver — all the same
            // answer here, and none of them is worth failing the command over.
            return [];
        }

        $described = [];

        foreach ($columns as $column) {
            $described[] = [
                'name' => $column['name'],
                // `type_name` is the bare name (`varchar`); `type` carries the
                // driver's full declaration (`varchar(255)`), which the
                // substring matches below would also answer to, but not as
                // readably.
                'type' => strtolower($column['type_name']),
            ];
        }

        return $described;
    }
}
