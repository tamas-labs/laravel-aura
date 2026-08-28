<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Table;

use Illuminate\Support\Traits\Macroable;

/**
 * The table-wide settings, in one object that knows where each one belongs.
 *
 * The contract splits them across three blocks — `header.settings`,
 * `body.settings`, `footer.settings` — which is a fact about the wire format,
 * not about how anyone thinks about a table. This collects them and does the
 * splitting on the way out.
 */
final class TableSettings
{
    use Macroable;

    /** @var array<string, mixed> */
    private array $header = [];

    /** @var array<string, mixed> */
    private array $body = [];

    /** @var array<string, mixed> */
    private array $footer = [];

    /**
     * An empty set of settings — every block is omitted until something is set.
     */
    public static function make(): self
    {
        return new self;
    }

    /**
     * Keep the header fixed while the rows scroll.
     */
    public function stickyHeader(bool $sticky = true): self
    {
        $this->header['sticky'] = $sticky;

        return $this;
    }

    /**
     * Fixed header height — a CSS length such as `48px`.
     */
    public function headerHeight(string $height): self
    {
        $this->header['height'] = $height;

        return $this;
    }

    /**
     * Striped rows.
     */
    public function striped(bool $striped = true): self
    {
        $this->body['striped'] = $striped;

        return $this;
    }

    /**
     * Highlight the row under the cursor.
     */
    public function hoverable(bool $hoverable = true): self
    {
        $this->body['hoverable'] = $hoverable;

        return $this;
    }

    /**
     * Keep the footer fixed to the bottom of the scroll container.
     */
    public function stickyFooter(bool $sticky = true): self
    {
        $this->footer['sticky'] = $sticky;

        return $this;
    }

    /**
     * Fixed footer height.
     */
    public function footerHeight(string $height): self
    {
        $this->footer['height'] = $height;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function headerSettings(): array
    {
        return $this->header;
    }

    /**
     * @return array<string, mixed>
     */
    public function bodySettings(): array
    {
        return $this->body;
    }

    /**
     * @return array<string, mixed>
     */
    public function footerSettings(): array
    {
        return $this->footer;
    }
}
