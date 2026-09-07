<?php

declare(strict_types=1);

/*
 * The package's own request-validation messages.
 *
 * These are the only strings this package sends to an end user: every one of
 * them is the body of a 422 that an ordinary click can produce. Everything else
 * the package says — `InvalidDefinition`, `UnsupportedRelation`, the dropped
 * entries an error batch reports back — is addressed to whoever wrote the table,
 * and stays in English.
 *
 * Laravel's own rule messages (`required`, `integer`, `in`) are not here; they
 * come from the framework's `validation.php` and are already translated.
 *
 * Override one line by publishing this file:
 *
 *     php artisan vendor:publish --tag=aura-lang
 */

return [

    'unknown_properties' => 'The :section carries properties the Aura contract does not define: :properties.',

    'not_sortable' => 'The field ":field" cannot be sorted by.',
    'not_searchable' => 'The field ":field" cannot be searched.',
    'not_filterable' => 'The field ":field" cannot be filtered by.',

    'list_too_long' => 'The :list list carries :count entries; this table offers :offered :list field(s), '
        .'and Aura sends at most one entry per field.',

    'selection_too_long' => 'The selection carries :count ids; this endpoint accepts :max. '
        .'Raise aura.limits.selected if that is genuinely too few.',

    'duplicate_field' => 'The :list list names the field ":field" more than once; '
        .'Aura sends at most one entry per field.',

    'scalar_id' => 'The :attribute must be a string or a number.',
    'scalar_value' => 'The :attribute must be a string, a number or a boolean.',

];
