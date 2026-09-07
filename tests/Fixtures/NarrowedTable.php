<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Tests\Fixtures;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use TamasLabs\Aura\Cell\Badge;
use TamasLabs\Aura\Cell\Condition;
use TamasLabs\Aura\Table\AuraTable;
use TamasLabs\Aura\Table\Column;
use TamasLabs\Aura\Table\ColumnGroup;

/**
 * A table that decides the shape of its own rows: the `$onlyDeclaredFields`
 * switch and the `transform()` hook, over a model with an eager-loaded
 * relation and a field only a condition names.
 *
 * @extends AuraTable<TypedUser>
 */
final class NarrowedTable extends AuraTable
{
    /**
     * @param  (Closure(Model): array<string, mixed>)|null  $rows  Stands in for an overridden transform().
     * @param  list<Column|ColumnGroup>|null  $definition  The columns, when the default set is not the subject.
     */
    public function __construct(
        private readonly ?Closure $rows = null,
        bool $narrow = true,
        private readonly ?array $definition = null,
        ?string $resource = null,
    ) {
        $this->onlyDeclaredFields = $narrow;
        $this->resource = $resource;
    }

    /**
     * @return Builder<TypedUser>
     */
    public function query(): Builder
    {
        return TypedUser::query()->with('company');
    }

    /**
     * @return list<Column|ColumnGroup>
     */
    public function columns(): array
    {
        return $this->definition ?? [
            Column::selection(),
            Column::make('last_name'),
            Column::make('company.name', 'Company'),
            // `balance` has no column: the condition is the only thing that
            // names it, and the row still has to carry it.
            Column::make('status')->as(
                Badge::make()->on('balance')->when(
                    Condition::gt(100),
                    static fn (Badge $badge): Badge => $badge->variant('danger'),
                ),
            ),
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
