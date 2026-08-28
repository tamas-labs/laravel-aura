<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Tests\Contract;

/**
 * Outcome of validating one payload against the Aura contract.
 */
final readonly class ContractResult
{
    /**
     * @param  list<string>  $issues  Human-readable `path: message` pairs; empty when valid.
     */
    public function __construct(
        public bool $valid,
        public array $issues,
    ) {}

    /**
     * The issues rendered for a test failure message.
     */
    public function report(): string
    {
        if ($this->issues === []) {
            return 'no issues reported';
        }

        return PHP_EOL.'  - '.implode(PHP_EOL.'  - ', $this->issues);
    }
}
