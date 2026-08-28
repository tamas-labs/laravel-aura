<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Request;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use TamasLabs\Aura\Query\FieldPermissions;

/**
 * One parsed, validated Aura request.
 *
 * Everything here arrived from the browser. Two rules follow from that, and both
 * are enforced on the way in rather than left to the caller:
 *
 * - every `field` is checked against {@see FieldPermissions} before it is kept;
 * - `paginate` is clamped to a configured ceiling, so a client cannot ask for
 *   the whole table in one page.
 *
 * Anything malformed raises {@see ValidationException}, which Laravel renders as
 * a 422 — never a 500, and never a silently ignored parameter.
 */
final readonly class AuraRequest
{
    /**
     * The complete set of keys the request schema allows; it sets
     * `additionalProperties: false`, so anything else is a contract break.
     */
    private const TOP_LEVEL_KEYS = [
        'page', 'paginate', 'sortable', 'searchable', 'filterable', 'globalSearch', 'selected',
    ];

    private const SORT_KEYS = ['field', 'direction'];

    private const SEARCH_KEYS = ['field', 'term', 'exact', 'min', 'max'];

    private const FILTER_KEYS = ['field', 'values'];

    /**
     * Methods that carry the payload as a JSON body; `GET` and `DELETE` carry it
     * as query parameters instead.
     */
    private const BODY_METHODS = ['POST', 'PUT', 'PATCH'];

    /**
     * @param  list<Sort>  $sortable  Sorts in the order the user added them.
     * @param  list<Search>  $searchable  Per-column searches.
     * @param  list<Filter>  $filterable  Per-column filters.
     * @param  list<string|int|float>  $selected  Ids of the selected rows — for the
     *                                            caller's bulk actions, never for the query.
     */
    public function __construct(
        public int $page,
        public int $paginate,
        public array $sortable,
        public array $searchable,
        public array $filterable,
        public ?string $globalSearch,
        public array $selected,
        public FieldPermissions $fields,
    ) {}

    /**
     * Parse and validate an incoming HTTP request.
     *
     * @param  int|null  $maxPaginate  Ceiling for `paginate`; defaults to `aura.pagination.max`.
     *
     * @throws ValidationException On anything the contract does not allow.
     */
    public static function fromHttp(Request $request, FieldPermissions $fields, ?int $maxPaginate = null): self
    {
        return self::fromArray(self::payload($request), $fields, $maxPaginate);
    }

    /**
     * Parse and validate an already-decoded payload.
     *
     * @param  array<array-key, mixed>  $payload
     *
     * @throws ValidationException
     */
    public static function fromArray(array $payload, FieldPermissions $fields, ?int $maxPaginate = null): self
    {
        self::rejectUnknownKeys($payload, self::TOP_LEVEL_KEYS, 'request');
        self::rejectUnknownNestedKeys($payload);

        $validated = self::validate($payload);

        $sortable = self::sorts(self::rows($validated, 'sortable'), $fields);
        $searchable = self::searches(self::rows($validated, 'searchable'), $fields);
        $filterable = self::filters(self::rows($validated, 'filterable'), $fields);

        return new self(
            page: self::integer($validated, 'page'),
            paginate: self::boundedPaginate(self::integer($validated, 'paginate'), $maxPaginate),
            sortable: $sortable,
            searchable: $searchable,
            filterable: $filterable,
            globalSearch: isset($validated['globalSearch']) && is_string($validated['globalSearch'])
                ? $validated['globalSearch']
                : null,
            selected: self::selected($validated),
            fields: $fields,
        );
    }

    /**
     * Where the payload lives for this method, per the request schema.
     *
     * @return array<array-key, mixed>
     */
    private static function payload(Request $request): array
    {
        if (! in_array($request->getMethod(), self::BODY_METHODS, true)) {
            return self::decodeQueryScalars($request->query());
        }

        return $request->isJson() ? (array) $request->json()->all() : $request->post();
    }

    /**
     * A query string carries every value as text, `exact=true` included, and the
     * `boolean` rule does not accept the word "true".
     *
     * Applied only to the query path: in a JSON body a string `"true"` really is
     * a contract violation, and stays one.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private static function decodeQueryScalars(array $payload): array
    {
        if (! isset($payload['searchable']) || ! is_array($payload['searchable'])) {
            return $payload;
        }

        $searchable = [];

        foreach ($payload['searchable'] as $key => $row) {
            if (is_array($row) && array_key_exists('exact', $row)) {
                $decoded = filter_var($row['exact'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

                if ($decoded !== null) {
                    $row['exact'] = $decoded;
                }
            }

            $searchable[$key] = $row;
        }

        $payload['searchable'] = $searchable;

        return $payload;
    }

    /**
     * `additionalProperties: false` applies to the nested objects too.
     *
     * This runs on the raw payload on purpose: the validator drops every key it
     * has no rule for, so by the time validation is done an unknown nested key
     * has already vanished and could never be reported.
     *
     * @param  array<array-key, mixed>  $payload
     *
     * @throws ValidationException
     */
    private static function rejectUnknownNestedKeys(array $payload): void
    {
        $shapes = [
            'sortable' => self::SORT_KEYS,
            'searchable' => self::SEARCH_KEYS,
            'filterable' => self::FILTER_KEYS,
        ];

        foreach ($shapes as $key => $allowed) {
            $rows = $payload[$key] ?? [];

            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (is_array($row)) {
                    self::rejectUnknownKeys($row, $allowed, $key);
                }
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function validate(array $payload): array
    {
        $scalarId = static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                $fail('The :attribute must be a string or a number.');
            }
        };

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($payload, [
            // No `max` rule on paginate: an oversized page is clamped, not rejected,
            // so a stale client keeps working instead of erroring at the user.
            'page' => ['required', 'integer', 'min:1'],
            'paginate' => ['required', 'integer', 'min:1'],
            'sortable' => ['sometimes', 'array'],
            'sortable.*' => ['array'],
            'sortable.*.field' => ['required', 'string'],
            'sortable.*.direction' => ['required', 'string', 'in:asc,desc'],
            'searchable' => ['sometimes', 'array'],
            'searchable.*' => ['array'],
            'searchable.*.field' => ['required', 'string'],
            'searchable.*.term' => ['sometimes', 'string'],
            'searchable.*.exact' => ['sometimes', 'boolean'],
            'searchable.*.min' => ['sometimes', 'nullable', $scalarId],
            'searchable.*.max' => ['sometimes', 'nullable', $scalarId],
            'filterable' => ['sometimes', 'array'],
            'filterable.*' => ['array'],
            'filterable.*.field' => ['required', 'string'],
            // `present`, not `required`: Laravel treats an empty array as missing,
            // while the contract requires the key and allows an empty selection.
            'filterable.*.values' => ['present', 'array'],
            'globalSearch' => ['sometimes', 'string'],
            'selected' => ['sometimes', 'array'],
            'selected.*' => ['required', $scalarId],
        ])->validate();

        return $validated;
    }

    /**
     * `additionalProperties: false` applies to the nested objects too.
     *
     * @param  array<array-key, mixed>  $subject
     * @param  list<string>  $allowed
     *
     * @throws ValidationException
     */
    private static function rejectUnknownKeys(array $subject, array $allowed, string $context): void
    {
        $unknown = array_diff(array_keys($subject), $allowed);

        if ($unknown === []) {
            return;
        }

        throw ValidationException::withMessages([
            $context => sprintf(
                'The %s carries properties the Aura contract does not define: %s.',
                $context,
                implode(', ', array_map(strval(...), $unknown)),
            ),
        ]);
    }

    /**
     * The rows of one array-shaped key, as plain arrays.
     *
     * @param  array<string, mixed>  $validated
     * @return list<array<array-key, mixed>>
     */
    private static function rows(array $validated, string $key): array
    {
        $value = $validated[$key] ?? [];

        if (! is_array($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<array-key, mixed>>  $rows
     * @return list<Sort>
     *
     * @throws ValidationException
     */
    private static function sorts(array $rows, FieldPermissions $fields): array
    {
        $sorts = [];

        foreach ($rows as $row) {
            $field = self::field($row);

            self::guard($fields->allowsSort($field), $field, 'sorted by');

            // Validation already rejected anything else; this narrows the type.
            $sorts[] = new Sort($field, ($row['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc');
        }

        return $sorts;
    }

    /**
     * @param  list<array<array-key, mixed>>  $rows
     * @return list<Search>
     *
     * @throws ValidationException
     */
    private static function searches(array $rows, FieldPermissions $fields): array
    {
        $searches = [];

        foreach ($rows as $row) {
            $field = self::field($row);

            self::guard($fields->allowsSearch($field), $field, 'searched');

            $term = $row['term'] ?? null;

            $searches[] = new Search(
                field: $field,
                term: is_string($term) ? $term : null,
                exact: filter_var($row['exact'] ?? false, FILTER_VALIDATE_BOOL),
                min: self::bound($row, 'min'),
                max: self::bound($row, 'max'),
            );
        }

        return $searches;
    }

    /**
     * @param  list<array<array-key, mixed>>  $rows
     * @return list<Filter>
     *
     * @throws ValidationException
     */
    private static function filters(array $rows, FieldPermissions $fields): array
    {
        $filters = [];

        foreach ($rows as $row) {
            $field = self::field($row);

            self::guard($fields->allowsFilter($field), $field, 'filtered by');

            $values = $row['values'] ?? [];

            $filters[] = new Filter($field, is_array($values) ? array_values($values) : []);
        }

        return $filters;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private static function field(array $row): string
    {
        $field = $row['field'] ?? '';

        return is_string($field) ? $field : '';
    }

    /**
     * One end of a range search; anything non-scalar is treated as an open end.
     *
     * @param  array<array-key, mixed>  $row
     */
    private static function bound(array $row, string $key): string|int|float|null
    {
        $value = $row[$key] ?? null;

        return is_string($value) || is_int($value) || is_float($value) ? $value : null;
    }

    /**
     * Refuse a field the table never offered for this operation.
     *
     * The message names the rejected field but never lists the permitted ones —
     * an error response is not a place to enumerate the schema.
     *
     * @throws ValidationException
     */
    private static function guard(bool $allowed, string $field, string $operation): void
    {
        if ($allowed) {
            return;
        }

        throw ValidationException::withMessages([
            'field' => sprintf('The field "%s" cannot be %s.', $field, $operation),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private static function integer(array $validated, string $key): int
    {
        $value = $validated[$key] ?? 1;

        return is_numeric($value) ? (int) $value : 1;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<string|int|float>
     */
    private static function selected(array $validated): array
    {
        $value = $validated['selected'] ?? [];

        if (! is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $id) {
            if (is_string($id) || is_int($id) || is_float($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Clamp the page size to the configured ceiling.
     */
    private static function boundedPaginate(int $paginate, ?int $maxPaginate): int
    {
        $max = $maxPaginate ?? config('aura.pagination.max');
        $max = is_numeric($max) ? (int) $max : $paginate;

        return $max > 0 ? min($paginate, $max) : $paginate;
    }
}
