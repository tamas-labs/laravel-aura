<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Cell;

use BackedEnum;
use Illuminate\Support\Str;
use TamasLabs\Aura\Contracts\AuraIcon;
use TamasLabs\Aura\Contracts\AuraOption;
use TamasLabs\Aura\Contracts\AuraVariant;

/**
 * Turns a backed enum into the lookup tables the cell configs use.
 *
 * The three interfaces are separate and all optional: an enum that implements
 * none still produces a usable table, with case names read as labels. What it
 * cannot produce is colours — those have to come from somewhere, and inventing
 * them per case is worse than leaving them out.
 */
final class EnumPresentation
{
    /**
     * A `mapping` for {@see Badge}: one entry per case, keyed by its value.
     *
     * @param  class-string<BackedEnum>  $enum
     * @return array<string, array<string, mixed>>
     */
    public static function badges(string $enum): array
    {
        $mapping = [];

        foreach ($enum::cases() as $case) {
            $entry = ['label' => self::label($case)];

            if ($case instanceof AuraVariant) {
                $entry['variant'] = $case->variant();
            }

            if ($case instanceof AuraIcon) {
                $entry['icon'] = $case->icon();
            }

            $mapping[(string) $case->value] = $entry;
        }

        return $mapping;
    }

    /**
     * The label of one case: its own, or its name read as a title.
     */
    public static function label(BackedEnum $case): string
    {
        return $case instanceof AuraOption ? $case->label() : Str::headline($case->name);
    }
}
