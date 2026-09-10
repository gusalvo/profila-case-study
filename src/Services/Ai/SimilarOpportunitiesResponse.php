<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * SimilarOpportunitiesResponse — DTO returned by {@see AiClient::generateSimilarBusinessOpportunities}.
 *
 * Holds the raw JSON string from Anthropic (shape: osservato / interpretazione / opportunità[])
 * per-call usage metrics. The orchestrator ({@see \App\Services\Discovery\SimilarOpportunitySynthesizer})
 * calls JsonExtractor on rawText and applies the PrudentLanguageGate before persisting.
 *
 * Named `rawText` (not `text`) because the payload is JSON, not plain prose
 * same boundary discipline as IdeasAiResponse / BriefAiResponse.
 *
 * / GR.
 */
final readonly class SimilarOpportunitiesResponse
{
    public function __construct(
        public string $rawText,   // raw JSON from Anthropic; JsonExtractor::extract() called by orchestrator
        public AiUsageMetrics $usage,
    ) {
    }
}
