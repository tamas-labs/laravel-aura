<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use TamasLabs\Aura\Table\AuraTable;
use TamasLabs\Aura\Table\Column;

/**
 * A table with caching on, counting how often it actually builds — the only way
 * to tell a cache that works from one that is merely not in the way.
 *
 * @extends AuraTable<TypedUser>
 */
final class CachedTable extends AuraTable
{
    public static int $builds = 0;

    protected bool $cache = true;

    /**
     * @return Builder<TypedUser>
     */
    public function query(): Builder
    {
        return TypedUser::query();
    }

    /**
     * @return list<Column>
     */
    public function columns(): array
    {
        self::$builds++;

        return [
            Column::make('last_name')->sortable()->searchable()->globalSearch(),
            Column::make('status')->filterable(),
            Column::make('balance')->sortable(),
        ];
    }
}
