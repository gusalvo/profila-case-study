<?php

namespace App\Services\Ai\Exceptions;

/**
 * Thrown when JsonExtractor (Plan 03) exhausts its 1-retry budget on a
 * malformed model response.
 *
 * Mapped by Brief\Show (Plan 05) to IT verbatim
 * "Il brief è stato generato in un formato non valido. Riprova."
 *
 * Logged via AiUsageLogger with status = AiUsageStatus::ErrorJson.
 */
class AiInvalidJsonException extends AiException
{
}
