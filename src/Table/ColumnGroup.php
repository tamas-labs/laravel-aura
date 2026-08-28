<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Table;

use Illuminate\Support\Traits\Macroable;
use TamasLabs\Aura\Exceptions\InvalidDefinition;

/**
 * A heading that sits above several columns, making the header two rows deep.
 *
 * The group cell spans its children; the children keep their own cells in the
 * second row. Columns that are not in any group span both rows instead, so the
 * header stays rectangular without the caller counting anything.
 */
final class ColumnGroup
{
    use Macroable;

    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param  list<Column>  $columns
     */
    private function __construct(private readonly string $content, private readonly array $columns) {}

    /**
     * @param  list<Column>  $columns
     *
     * @throws InvalidDefinition When the group spans fewer than two columns.
     */
    public static function make(string $content, array $columns): self
    {
        if (count($columns) < 2) {
            throw InvalidDefinition::emptyGroup($content, count($columns));
        }

        return new self($content, $columns);
    }

    /**
     * Heading alignment. Centred by default — a group cell sits above its
     * children rather than above any one of them.
     *
     * @param  'start'|'center'|'end'  $align
     */
    public function align(string $align): self
    {
        return $this->set('align', $align);
    }

    /**
     * Any other header-cell key.
     */
    public function set(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * The columns this group covers.
     *
     * @return list<Column>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * The grouping cell itself. Its `colspan` is the number of children, which
     * is why a group of one is refused: the contract requires a field-less cell
     * to span at least two columns.
     *
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        return array_merge([
            'content' => $this->content,
            'colspan' => count($this->columns),
            'align' => 'center',
        ], $this->attributes);
    }
}
