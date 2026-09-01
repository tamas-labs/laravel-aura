<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Table;

/**
 * The footer block — the same rows and cells as the header, validated by the
 * very same schema.
 *
 * A footer cell that names no field is a grouping cell, so it has to span at
 * least two columns; {@see Column::heading()} is the usual way to build one.
 */
final class Footer
{
    /** @var list<list<Column>> */
    private array $rows = [];

    /**
     * A footer of one row.
     */
    public static function make(Column ...$cells): self
    {
        return (new self)->row(...$cells);
    }

    /**
     * Add a row below the ones already declared.
     */
    public function row(Column ...$cells): self
    {
        $this->rows[] = array_values($cells);

        return $this;
    }

    /**
     * @return list<list<Column>>
     *
     * @internal
     */
    public function rows(): array
    {
        return $this->rows;
    }
}
