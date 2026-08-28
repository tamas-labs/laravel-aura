<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Cell;

use TamasLabs\Aura\Response\NumericFields;

/**
 * One condition of an `if` branch.
 *
 * Aura's condition objects carry the operator and the configuration in the same
 * array — `['eq' => 'active', 'variant' => 'success']` — and the *first
 * recognised operator key wins, in key order*. Keeping the operator in its own
 * object means a config key can never be mistaken for an operator, and that a
 * branch cannot accidentally carry two.
 *
 * The contract lists 24 operator keys, but five of them are pure aliases
 * (`neq`, `bigger`, `biggerOrEqual`, `smaller`, `smallerOrEqual`). Only the 19
 * distinct ones are offered here; an alias adds a second spelling and no
 * meaning.
 */
final readonly class Condition
{
    /**
     * Operators Aura compares numerically. Both sides have to be numbers — or
     * both have to parse as dates — or the comparison is simply false, with no
     * warning anywhere. See {@see NumericFields}.
     */
    private const NUMERIC = ['gt', 'gte', 'lt', 'lte', 'between'];

    private function __construct(
        public string $operator,
        public mixed $value,
    ) {}

    /**
     * Equal — a strict comparison, so `1` does not match `'1'`.
     */
    public static function eq(mixed $value): self
    {
        return new self('eq', $value);
    }

    /**
     * Not equal. Strict, like {@see self::eq()}.
     */
    public static function ne(mixed $value): self
    {
        return new self('ne', $value);
    }

    /**
     * Greater than. Numeric or date comparison — see {@see self::NUMERIC}.
     */
    public static function gt(mixed $value): self
    {
        return new self('gt', $value);
    }

    /**
     * Greater than or equal.
     */
    public static function gte(mixed $value): self
    {
        return new self('gte', $value);
    }

    /**
     * Less than.
     */
    public static function lt(mixed $value): self
    {
        return new self('lt', $value);
    }

    /**
     * Less than or equal.
     */
    public static function lte(mixed $value): self
    {
        return new self('lte', $value);
    }

    /**
     * Inside the inclusive range.
     */
    public static function between(mixed $min, mixed $max): self
    {
        return new self('between', [$min, $max]);
    }

    /**
     * One of these values. Membership is strict.
     *
     * @param  list<mixed>  $values
     */
    public static function in(array $values): self
    {
        return new self('in', $values);
    }

    /**
     * None of these values.
     *
     * @param  list<mixed>  $values
     */
    public static function notIn(array $values): self
    {
        return new self('notIn', $values);
    }

    /**
     * Substring match. String values only — a number never matches.
     */
    public static function contains(string $value): self
    {
        return new self('contains', $value);
    }

    /**
     * Prefix match.
     */
    public static function startsWith(string $value): self
    {
        return new self('startsWith', $value);
    }

    /**
     * Suffix match.
     */
    public static function endsWith(string $value): self
    {
        return new self('endsWith', $value);
    }

    /**
     * Matches this regular expression.
     *
     * The pattern is a JavaScript `RegExp` source string — no delimiters, no
     * PHP modifiers, and PCRE-only syntax will not compile in the browser. A
     * pattern that fails to compile makes the branch false, silently.
     */
    public static function regex(string $pattern): self
    {
        return new self('regex', $pattern);
    }

    /**
     * The value is `null` (or missing).
     */
    public static function isNull(): self
    {
        return new self('null', true);
    }

    /**
     * The value is neither `null` nor missing.
     */
    public static function notNull(): self
    {
        return new self('notNull', true);
    }

    /**
     * The value is empty.
     *
     * Aura counts `null`, `''`, `0` and `false` as empty — and, despite what
     * the schema says, *not* `[]` or `{}` (`evaluate-condition.ts`, `isEmpty`).
     * A zero-valued number is therefore empty here.
     */
    public static function isEmpty(): self
    {
        return new self('empty', true);
    }

    /**
     * The value is not empty. See {@see self::isEmpty()} for what that means.
     */
    public static function notEmpty(): self
    {
        return new self('notEmpty', true);
    }

    /**
     * The value is exactly boolean `true`.
     *
     * Exact, not truthy — `1` does not match, whatever the schema's description
     * says (`evaluate-condition.ts`: `fieldValue === true`). A `tinyint` column
     * has to be cast to `bool` on the model or this is always false.
     */
    public static function isTrue(): self
    {
        return new self('true', true);
    }

    /**
     * The value is exactly boolean `false`. Exact — see {@see self::isTrue()}.
     */
    public static function isFalse(): self
    {
        return new self('false', true);
    }

    /**
     * Does this operator compare numbers?
     */
    public function isNumeric(): bool
    {
        return in_array($this->operator, self::NUMERIC, true);
    }
}
