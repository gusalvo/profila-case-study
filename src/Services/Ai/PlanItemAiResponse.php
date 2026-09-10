<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * PlanItemAiResponse — DTO returned by {@see AiClient::generatePlanItem} and
 * the 3 regenerate methods (regenerateCaption / regenerateVisual / regenerateFull).
 *
 * Mirrors {@see BriefAiResponse} shape verbatim (DTO pattern).
 * Immutable readonly class (PHP 8.2). Holds the raw text response from the
 * Sonnet/Haiku model + per-call usage metrics. PlanItemGenerator
 * then runs the raw text through JsonExtractor + schema validation before
 * persisting PlanItem rows.
 *
 * `rawText` is intentionally a string (not a parsed array) — keeping the
 * boundary "pure HTTP" lets the orchestrator own the parse/validate/retry
 * logic in one place (boundary table).
 */
final readonly class PlanItemAiResponse
{
    public function __construct(
        public string $rawText,
        public AiUsageMetrics $usage,
    ) {
    }
}
