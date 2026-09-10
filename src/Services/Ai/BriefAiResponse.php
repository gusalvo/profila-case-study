<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * BriefAiResponse — DTO returned by {@see AiClient::generateBrief}.
 *
 * Immutable readonly class (PHP 8.2). Holds the raw text response from the
 * model + per-call usage metrics. The Plan 04 BriefGenerator orchestrator
 * then runs the raw text through {@see JsonExtractor} + a schema validator
 * before persisting.
 *
 * `rawText` is intentionally a string (not a parsed array) — keeping the
 * boundary "pure HTTP" lets the orchestrator own the parse/validate/retry
 * logic in one place (boundary table).
 */
final readonly class BriefAiResponse
{
    public function __construct(
        public string $rawText,
        public AiUsageMetrics $usage,
    ) {
    }
}
