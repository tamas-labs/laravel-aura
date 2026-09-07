<?php

declare(strict_types=1);

/*
 * The Hungarian half of the same file. Every key in `en` has to appear here and
 * nowhere else — `tests/LocalizationTest.php` fails on a key present in one
 * locale and missing from the other, which is the only way two files like these
 * stay together.
 *
 * The comments stay in English by the repository's convention; the lines
 * themselves are what a Hungarian user reads.
 */

return [

    'unknown_properties' => 'A(z) :section olyan tulajdonságokat hordoz, amelyeket az Aura kontraktus nem definiál: :properties.',

    'not_sortable' => 'A(z) ":field" mező nem rendezhető.',
    'not_searchable' => 'A(z) ":field" mezőben nem lehet keresni.',
    'not_filterable' => 'A(z) ":field" mező nem szűrhető.',

    'list_too_long' => 'A(z) :list lista :count elemet hordoz; ez a tábla :offered :list mezőt kínál, '
        .'az Aura pedig mezőnként legfeljebb egy elemet küld.',

    'selection_too_long' => 'A kijelölés :count azonosítót hordoz; ez a végpont :max darabot fogad el. '
        .'Ha ez tényleg kevés, emeld meg az aura.limits.selected értékét.',

    'duplicate_field' => 'A(z) :list lista többször nevezi meg a(z) ":field" mezőt; '
        .'az Aura mezőnként legfeljebb egy elemet küld.',

    'scalar_id' => 'A(z) :attribute mezőnek szövegnek vagy számnak kell lennie.',
    'scalar_value' => 'A(z) :attribute mezőnek szövegnek, számnak vagy logikai értéknek kell lennie.',

];
