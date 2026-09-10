<?php

namespace App\Services\Ai\Exceptions;

/**
 * Thrown when the HTTP call to Anthropic exceeds the 60-second timeout.
 *
 * Mapped by Brief\Show to the same 5xx/network IT verbatim
 * "Non siamo riusciti a generare il brief in questo momento. Puoi riprovare."
 *
 * Logged via AiUsageLogger with status = AiUsageStatus::ErrorTimeout
 * (distinct from ErrorFiveXX so cost/regression dashboards can separate
 * stuck-connection failures from upstream service errors).
 */
class AiTimeoutException extends AiException
{
}
