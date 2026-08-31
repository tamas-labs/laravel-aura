<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | `paginate` arrives from the client on every request, so it is attacker
    | controlled: `max` is the hard upper bound the request layer clamps to,
    | never a suggestion. An oversized value is clamped rather than rejected, so
    | a stale client keeps working instead of erroring at the user.
    |
    | There is no default page size on purpose. The request contract makes
    | `paginate` required, and defaulting a missing one would turn a broken
    | client into a silently short page instead of a 422.
    |
    | Aura's contract requires `meta.last_page` and `meta.total`, which only a
    | LengthAwarePaginator provides — simplePaginate/cursorPaginate cannot
    | satisfy the response schema.
    |
    */

    'pagination' => [
        'max' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Request limits
    |--------------------------------------------------------------------------
    |
    | The rest of the payload is attacker controlled too, and without ceilings a
    | single request can ask for an unbounded amount of work. Exceeding one of
    | these is a 422, not a clamp: unlike a stale client's oversized `paginate`,
    | nothing legitimate produces them.
    |
    | Note what is *absent*. The `sortable`, `searchable` and `filterable` lists
    | have no key here because they need none: Aura keeps a single entry per
    | field, so the column whitelist is already their exact ceiling — a table
    | offering three sortable columns accepts three sorts. That bound is derived
    | from the columns, so it cannot go stale the way a number here can.
    |
    | `selected` is the one list nothing on the server can bound: the selection
    | survives paging, so it grows with what the user ticks, not with the table.
    |
    */

    'limits' => [
        // Ids in `selected`. These never reach the query — they are the caller's
        // to act on — but they are held in memory and handed on.
        'selected' => 1000,

        // Values in one filter dropdown. Not derived from the column's
        // `elements`, because `elements` is optional: a column that declares
        // none lets Aura build the options from the loaded rows.
        'values' => 200,

        // Characters in `globalSearch` and in a `searchable[].term`. Aura sets
        // no maxlength on either input, and a LIKE term longer than the column
        // it searches cannot match anything — it can only cost.
        'term' => 255,
    ],

];
