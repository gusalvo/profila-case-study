<?php

namespace App\Services\Ai\Exceptions;

/**
 * Thrown by JsonExtractor when an AI response cannot be coerced into a JSON
 * object even after the retry pass.
 *
 * Extends AiException (not RuntimeException) because it semantically belongs
 * to the AI-failure family — the BriefGenerator catch-all handles it together
 * with the typed siblings (AiInvalidJsonException, AiSchemaInvalidException).
 *
 * Plan 03 (Anthropic client + JsonExtractor) is the producer. Logged
 * AiUsageLogger with status = AiUsageStatus::ErrorJson.
 */
class InvalidAiResponseException extends AiException
{
}
