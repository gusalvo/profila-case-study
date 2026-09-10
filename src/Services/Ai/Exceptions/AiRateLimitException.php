<?php

namespace App\Services\Ai\Exceptions;

/**
 * Thrown when the Anthropic API returns HTTP 429 (rate limit).
 *
 * Mapped by Brief\Show to IT verbatim
 * "Il servizio AI è momentaneamente occupato. Riprova tra poco."
 *
 * Logged via AiUsageLogger with status = AiUsageStatus::ErrorRateLimit.
 */
class AiRateLimitException extends AiException
{
}
