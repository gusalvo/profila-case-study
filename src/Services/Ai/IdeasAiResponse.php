<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * IdeasAiResponse — DTO returned by {@see AiClient::generateIdeas}.
 *
 * Mirrors {@see BriefAiResponse} shape verbatim (DTO pattern).
 * Immutable readonly class (PHP 8.2). Holds the raw text response from the
 * Haiku model + per-call usage metrics. IdeaGenerator then runs
 * the raw text through JsonExtractor + schema validation before persisting
 * ContentIdea rows.
 *
 * `rawText` is intentionally a string (not a parsed array) — keeping the
 * boundary "pure HTTP" lets the orchestrator own the parse/validate/retry
 * logic in one place (boundary table).
 */
final readonly class IdeasAiResponse
{
    public function __construct(
        public string $rawText,
        public AiUsageMetrics $usage,
    ) {
    }
}
