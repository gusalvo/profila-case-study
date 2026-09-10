<?php

namespace App\Services\Ai\Exceptions;

/**
 * Thrown when the Anthropic API returns HTTP 5xx or a network error.
 *
 * Mapped by Brief\Show (Plan 05) to IT verbatim
 * "Non siamo riusciti a generare il brief in questo momento. Puoi riprovare."
 *
 * Logged via AiUsageLogger with status = AiUsageStatus::ErrorFiveXX.
 */
class AiTransientException extends AiException
{
}
