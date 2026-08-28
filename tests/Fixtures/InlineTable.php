<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use TamasLabs\Aura\Cell\CellRules;
use TamasLabs\Aura\Table\AuraTable;
use TamasLabs\Aura\Table\Column;
use TamasLabs\Aura\Table\ColumnGroup;
use TamasLabs\Aura\Table\Footer;
use TamasLabs\Aura\Table\TableSettings;

/**
 * A table assembled from a column list, for the cases that are about one rule
 * rather than about a realistic table.
 *
 * @extends AuraTable<TypedUser>
 */
final class InlineTable extends AuraTable
{
    /**
     * @param  list<Column|ColumnGroup>  $definition
     */
    public function __construct(
        private readonly array $definition,
        private readonly ?Footer $footerBlock = null,
        private readonly ?TableSettings $tableSettings = null,
        private readonly ?CellRules $rules = null,
    ) {}

    /**
     * @return Builder<TypedUser>
     */
    public function query(): Builder
    {
        return TypedUser::query();
    }

    /**
     * @return list<Column|ColumnGroup>
     */
    public function columns(): array
    {
        return $this->definition;
    }

    /**
     * {@inheritDoc}
     */
    public function footer(): ?Footer
    {
        return $this->footerBlock;
    }

    /**
     * {@inheritDoc}
     */
    public function settings(): TableSettings
    {
        return $this->tableSettings ?? TableSettings::make();
    }

    /**
     * {@inheritDoc}
     */
    public function rowRules(): ?CellRules
    {
        return $this->rules;
    }
}
