<?php

namespace App\Services\Ai\Exceptions;

/**
 * Thrown when the AI response parses as JSON but fails the BriefSchemaValidator
 * (missing required field, wrong type, etc.).
 *
 * Mapped by Brief\Show (Plan 05) to the same JSON-invalid IT verbatim
 * "Il brief è stato generato in un formato non valido. Riprova."
 *
 * Logged via AiUsageLogger with status = AiUsageStatus::ErrorSchema
 * (separate from ErrorJson so we can monitor schema-drift incidents).
 */
class AiSchemaInvalidException extends AiException
{
}
