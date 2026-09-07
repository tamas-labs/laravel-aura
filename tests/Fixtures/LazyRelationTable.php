<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Tests\Fixtures;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use TamasLabs\Aura\Table\AuraTable;
use TamasLabs\Aura\Table\Column;
use TamasLabs\Aura\Table\ColumnGroup;

/**
 * A table reading through a relation, with the eager load under the test's
 * control — the subject of the missing-field warning.
 *
 * @extends AuraTable<TypedUser>
 */
final class LazyRelationTable extends AuraTable
{
    /**
     * @param  bool  $eager  Whether query() loads the relation the columns read.
     * @param  list<Column|ColumnGroup>|null  $definition  The columns, when the default set is not the subject.
     * @param  (Closure(Model): array<string, mixed>)|null  $rows  Stands in for an overridden transform().
     */
    public function __construct(
        private readonly bool $eager = false,
        private readonly ?array $definition = null,
        private readonly ?Closure $rows = null,
    ) {}

    /**
     * @return Builder<TypedUser>
     */
    public function query(): Builder
    {
        $query = TypedUser::query();

        return $this->eager ? $query->with('company') : $query;
    }

    /**
     * @return list<Column|ColumnGroup>
     */
    public function columns(): array
    {
        return $this->definition ?? [
            Column::make('last_name'),
            Column::make('company.name', 'Company'),
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function transform(Model $model): array
    {
        return $this->rows === null ? parent::transform($model) : ($this->rows)($model);
    }
}
