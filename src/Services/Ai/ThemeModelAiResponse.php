<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * ThemeModelAiResponse — DTO returned by {@see AiClient::generateThemeModel}.
 *
 * Holds a DECODED structured payload for the theme-model pipeline.
 * Unlike {@see PlanLogicResponse} (flat text) or {@see PlanItemAiResponse} (raw text
 * for orchestrator to parse), this DTO decodes the AI's JSON at the HTTP boundary
 * into a typed structure ready for rollup to merge against PHP-computed weights.
 *
 * CRITICAL: `$themes` carries NO weight/percentage fields. Weights are
 * PHP-computed at rollup. The AI returns ONLY the strategic layer
 * certezza + funnel_stage + reasoning.
 *
 * Schema (per theme entry in `->themes`)
 * [
 * 'certezza' => 'alta' | 'media'
 * 'funnel_stage' => <FunnelStage::value string — e.g. 'ispirazione'>
 * 'reasoning' => <Italian "perché te lo propongo" line>
 * ]
 *
 * Mirrors {@see PlanLogicResponse} / {@see PlanItemAiResponse} readonly DTO pattern.
 */
final readonly class ThemeModelAiResponse
{
    /**
     * @param array<string, array{certezza: string, funnel_stage: string, reasoning: string}> $themes
     * Keyed by theme label. Values are per-theme strategic assessment (no weights).
     * @param AiUsageMetrics $usage Per-call usage telemetry.
     */
    public function __construct(
        public array $themes,
        public AiUsageMetrics $usage,
    ) {
    }
}
