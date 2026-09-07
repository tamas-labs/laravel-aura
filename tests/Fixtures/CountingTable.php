<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use TamasLabs\Aura\Table\AuraTable;
use TamasLabs\Aura\Table\Column;

/**
 * A table that counts how often its `query()` is asked for.
 *
 * The method is the host application's, and it is free to do work — scope to
 * the current tenant, read `Auth::user()`, log. Building the definition needs
 * the model rather than a second builder, so the count is the assertion.
 *
 * @extends AuraTable<TypedUser>
 */
final class CountingTable extends AuraTable
{
    public static int $queries = 0;

    public function __construct(bool $cached = false)
    {
        $this->cache = $cached;
    }

    /**
     * @return Builder<TypedUser>
     */
    public function query(): Builder
    {
        self::$queries++;

        return TypedUser::query()->with('company');
    }

    /**
     * @return list<Column>
     */
    public function columns(): array
    {
        return [
            Column::selection(),
            Column::make('last_name')->sortable()->searchable(),
            Column::make('company.name', 'Company')->sortable(),
            Column::make('status')->filterable(),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function cacheKey(): string
    {
        return 'aura.table.counting';
    }
}
