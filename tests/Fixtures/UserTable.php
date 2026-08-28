<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use TamasLabs\Aura\Table\AuraTable;
use TamasLabs\Aura\Table\Column;
use TamasLabs\Aura\Table\TableSettings;

/**
 * A table defined the way a host application would define one — the end-to-end
 * subject.
 *
 * @extends AuraTable<TypedUser>
 */
final class UserTable extends AuraTable
{
    /**
     * @return Builder<TypedUser>
     */
    public function query(): Builder
    {
        return TypedUser::query()->with('company');
    }

    /**
     * @return list<Column>
     */
    public function columns(): array
    {
        return [
            Column::selection(),
            Column::combined('full_name', ['first_name', 'last_name'], 'Name')
                ->sortable()
                ->searchable()
                ->reference('last_name'),
            Column::make('company.name', 'Company')->sortable()->globalSearch(),
            Column::make('status')->filterable(),
            Column::make('balance')->sortable()->searchable(),
            Column::make('created_at')->sortable()->searchable(),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function settings(): TableSettings
    {
        return TableSettings::make()->stickyHeader()->striped()->hoverable();
    }
}
