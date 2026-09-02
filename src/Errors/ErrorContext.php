<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Errors;

use Illuminate\Http\Request;

/**
 * What the server knows about a batch that the batch itself does not say.
 *
 * Aura's payload names no table, no page and no user — there is no `storeId`,
 * no URL and no version field in it (D7 leaves the payload alone). Every one of
 * these is therefore an approximation, and each is worth exactly what its
 * source is worth: `Referer` is client-supplied and often absent, and the user
 * is known only on a same-origin request, where the native `fetch()` sends the
 * session cookie.
 *
 * @internal
 */
final readonly class ErrorContext
{
    /**
     * @param  string  $receivedAt  ISO 8601 time the batch arrived — server clock,
     *                              unlike the entry's own `timestamp`.
     * @param  string|null  $ip  Client address, as the framework resolved it.
     * @param  string|null  $userAgent  `User-Agent` header, truncated.
     * @param  string|null  $referer  `Referer` header — the closest thing to "which page".
     * @param  int|string|null  $userId  Authenticated user, or `null` cross-origin.
     */
    public function __construct(
        public string $receivedAt,
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?string $referer = null,
        public int|string|null $userId = null,
    ) {}

    /**
     * Read the context off an incoming request.
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            receivedAt: gmdate('Y-m-d\TH:i:s.v\Z'),
            ip: $request->ip(),
            userAgent: AuraErrorRecord::text($request->userAgent(), AuraErrorRecord::MAX_NAME),
            referer: AuraErrorRecord::text($request->headers->get('referer'), AuraErrorRecord::MAX_TEXT),
            userId: self::userId($request),
        );
    }

    /**
     * The authenticated user's id, if there is one.
     *
     * Read off the request rather than through the `Auth` facade, so nothing
     * here depends on a guard being resolvable: the ingest route runs outside
     * the `web` group by necessity — Aura sends no CSRF token — and a telemetry
     * endpoint must not fail over the shape of the host's authentication.
     */
    private static function userId(Request $request): int|string|null
    {
        try {
            $id = $request->user()?->getAuthIdentifier();
        } catch (\Throwable) {
            return null;
        }

        return is_int($id) || is_string($id) ? $id : null;
    }
}
