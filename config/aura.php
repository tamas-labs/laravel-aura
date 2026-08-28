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

];
