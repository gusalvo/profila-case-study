<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * FirstImpressionResponse — DTO returned by {@see AiClient::analyzeFirstImpression}.
 *
 * Mirrors {@see IdeasAiResponse} shape verbatim (DTO pattern).
 * Immutable readonly class (PHP 8.2). Holds the raw text response from the
 * Haiku model + per-call usage metrics. ScanFirstImpressionJob then
 * parses the 5 fields from rawText before storing in Cache.
 *
 * `rawText` is intentionally a string (not a parsed array) — keeping the
 * boundary "pure HTTP" lets the orchestrator own the parse/validate/retry
 * logic in one place (boundary table).
 *
 *. Haiku routing: analyzeFirstImpression
 * always uses claude-haiku-4-5 (stage-1 impression; Sonnet is used only for
 * the confirmed Brief in stage 2).
 */
final readonly class FirstImpressionResponse
{
    public function __construct(
        public string $rawText,
        public AiUsageMetrics $usage,
    ) {
    }
}
