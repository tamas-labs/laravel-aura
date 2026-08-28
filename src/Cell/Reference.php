<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Cell;

use TamasLabs\Aura\Cell\Concerns\HasElement;
use TamasLabs\Aura\Cell\Concerns\HasFormatting;
use TamasLabs\Aura\Cell\Concerns\HasMapping;
use TamasLabs\Aura\Cell\Concerns\HasTypography;

/**
 * The row's own value, through the formatter chain.
 *
 * This is the type to reach for when a column needs conditional formatting but
 * still shows its data: the `field` defaults to the column's, so
 * `Reference::make()` on a column is the identity renderer plus whatever you
 * add to it.
 *
 * ```php
 * Column::make('balance')->currency()->as(
 *     Reference::make()->when(Condition::lt(0), fn (Reference $r) => $r->color('danger'))
 * );
 * ```
 */
final class Reference extends CellConfig
{
    use HasElement;
    use HasFormatting;
    use HasMapping;
    use HasTypography;

    /**
     * @param  string|null  $field  Item field to read. Defaults to the column's own field.
     */
    public static function make(?string $field = null): self
    {
        $config = new self;

        if ($field !== null) {
            $config->set('field', $field);
        }

        return $config;
    }

    /**
     * Several fields, rendered joined.
     *
     * @param  list<string>  $fields
     */
    public static function combined(array $fields, string $separator = ' '): self
    {
        $config = new self;
        $config->set('fields', $fields);
        $config->set('separator', $separator);

        return $config;
    }

    public function type(): string
    {
        return 'reference';
    }

    /**
     * Fixed text, ignoring the row. Takes priority over `fields` and `field`.
     */
    public function value(string $value): self
    {
        return $this->set('value', $value);
    }

    /**
     * Text placed between joined `fields` values.
     */
    public function separator(string $separator): self
    {
        return $this->set('separator', $separator);
    }

    protected function readsField(): bool
    {
        return true;
    }

    protected function formats(): bool
    {
        return true;
    }

    protected function requires(): array
    {
        return [[['field'], ['fields'], ['mapping'], ['value']]];
    }
}
