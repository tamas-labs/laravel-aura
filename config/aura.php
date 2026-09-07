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

    /*
    |--------------------------------------------------------------------------
    | Definition cache
    |--------------------------------------------------------------------------
    |
    | Only read by a table that opts in with `protected bool $cache = true`.
    | Both keys exist for the same reason: the definition cache is the package's
    | own, and it should be possible to say where it lives and to invalidate all
    | of it without naming every table class.
    |
    | There is deliberately no lock and no `remember()` here. A build is pure
    | PHP — measured at 0.14 ms for eight columns and 0.49 ms for forty, each
    | carrying a badge with two conditions — so a cold cache does not produce a
    | thundering herd worth serialising: making the other requests wait on a
    | lock costs a round trip to buy back half a millisecond of CPU. And
    | `remember()` would return whatever non-null value it found, which is
    | exactly the entry the read path refuses to trust.
    |
    */

    'cache' => [

        // Which cache store the definition goes to. `null` is the application's
        // default — and the default is worth a thought before leaving it: an
        // `array` store makes the cache silently per-request (per worker under
        // Octane, which is worse: two workers can serve two different
        // definitions), and a shared Redis under memory pressure can evict it
        // at any time, which is harmless but means the cache never warms.
        //
        // Naming a store of its own also gives the group flush a driver cannot:
        //
        //   php artisan cache:clear aura
        //
        'store' => env('AURA_CACHE_STORE'),

        // What the default `cacheKey()` puts in front of the table class name.
        // Bumping it misses every table's entry at once, which is the answer to
        // "the columns changed in this deploy" that does not need a list of
        // table classes and does not need a store supporting tags — `file` and
        // `database` do not, so `Cache::tags()` is not available to a package
        // that cannot choose the driver.
        //
        //   AURA_CACHE_PREFIX=aura.table.v2.
        //
        // It misses rather than flushes: the old entries stay until their TTL
        // runs out. A table overriding `cacheKey()` builds its own key and this
        // does not reach it.
        'prefix' => env('AURA_CACHE_PREFIX', 'aura.table.'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Error ingest
    |--------------------------------------------------------------------------
    |
    | Aura can POST its own error log to an endpoint (`errorReporting: true` +
    | `errorReportingEndpoint`). This section is that endpoint. Everything here
    | follows from what the client actually does, which is measured, not
    | assumed — see `.claude/docs/error-ingest.md`.
    |
    */

    'errors' => [

        // Off until asked for. A telemetry endpoint must not appear merely
        // because someone ran `composer require`, so this is the one switch
        // that has to be flipped deliberately — from the environment, so the
        // config file need not be published to turn it on.
        'enabled' => env('AURA_ERRORS_ENABLED', false),

        // Path the route is registered at, relative to the application root.
        // Whatever it is, the host application's Aura config has to name the
        // same URL in `errorReportingEndpoint`.
        'path' => 'aura/errors',

        // The route's middleware — and the reason this list is here rather than
        // hard-coded. Aura reports with a native `fetch()` and sends no CSRF
        // token, so the `web` group would answer 419, forever: the client takes
        // every non-2xx for a failure, retries four times, then re-queues the
        // batch behind an exponential backoff. Do not add `web` or
        // `VerifyCsrfToken` here. `throttle` is the default because a table
        // that is failing reports every 30 seconds, per browser tab.
        //
        // What to add is the other half of that sentence. Nothing here
        // authenticates the route: with these defaults one IP can write 60
        // requests a minute of `max_entries` each, and the fingerprint only
        // merges repeats of the *same* error — a varied message is a new row.
        // A guard is welcome, as long as it can answer 2xx for a legitimate
        // reporter, because whatever it rejects it will be handed again for as
        // long as the tab is open:
        //
        //   'middleware' => ['throttle:60,1', 'auth'],
        //
        // works when every page that reports is a page the user is logged into
        // and the endpoint is same-origin — `fetch()` defaults to
        // `credentials: 'same-origin'`, so the session cookie is sent, and a
        // cross-origin endpoint gets no cookie at all. Otherwise leave the
        // route open, keep the throttle, and put it where the public internet
        // does not reach it. The READMEs work through this.
        'middleware' => ['throttle:60,1'],

        // `log` writes through a log channel and needs no infrastructure;
        // `database` stores rows that can be filtered, counted and deduplicated
        // — and needs the published migration.
        'driver' => 'log',

        // Log channel for the `log` driver. `null` uses the default channel.
        'channel' => null,

        // Table the `database` driver writes to. The migration that creates it
        // is published, not loaded: a library that generates JSON and one that
        // creates a table on your database are different promises.
        //
        //   php artisan vendor:publish --tag=aura-error-migrations
        'table' => 'aura_errors',

        // Hard ceiling on the request body, in bytes. A single `HeaderValidator`
        // entry carries the whole `header` section it rejected in
        // `metadata.receivedValue`, and the client truncates nothing, so a
        // 100-entry batch is not bounded by anything on the browser side.
        // Over this the answer is 413 — one of the few codes worth spending,
        // because no retry can make the batch smaller.
        //
        // Storage protection, not memory protection: it is measured with
        // `strlen($request->getContent())`, and by then PHP has read and
        // buffered the whole body. The ceiling that protects the process is the
        // web server's — `client_max_body_size`, `post_max_size` — and this one
        // decides what gets stored, not what gets read.
        'max_payload' => 1048576,

        // Entries accepted from one batch. The client's own queue caps at 100;
        // anything beyond that is dropped, not rejected — the batch still
        // answers 202, because a 4xx would only make the client send it again.
        'max_entries' => 100,

        'metadata' => [

            // Whether `metadata` is stored at all. It is the only part of an
            // entry that can carry what a user typed: `SessionStateValidator`
            // hands over the persisted session state, which holds their search
            // terms and filter values. Turn it off where that matters.
            'store' => true,

            // Bytes kept per entry, after JSON encoding. Beyond this the value
            // is truncated, never the entry dropped — a too-large
            // `receivedValue` is exactly the case worth knowing about.
            'max_bytes' => 8192,
        ],

        // Whether storing is pushed onto the queue. Synchronous by default: the
        // work is one write, and a queue that is not running would turn a
        // report into a silently pending job.
        'queue' => false,
    ],

];
