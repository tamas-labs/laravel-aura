<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Exceptions;

use Throwable;

/**
 * Marker for every exception this package raises on its own behalf, so a host
 * application can catch the lot with one `catch (AuraException $e)`.
 *
 * Client input never reaches these: a malformed request fails validation and
 * becomes a 422. Everything here reports a mistake in the table definition.
 */
interface AuraException extends Throwable {}
