<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * PlanLogicResponse — DTO returned by {@see AiClient::generatePlanLogic}.
 *
 * Holds the "Logica del piano editoriale" narrative (one short paragraph
 * Profila's voice, always Italian) + per-call usage metrics. The orchestrator
 * ({@see \App\Services\Plans\PlanLogicGenerator}) stores the text into
 * editorial_plans.report_enrichment['plan_logic'] and logs the usage.
 *
 * Mirrors {@see FirstImpressionResponse}: a pure text payload, no parsing at
 * the HTTP boundary.
 */
final readonly class PlanLogicResponse
{
    public function __construct(
        public string $text,
        public AiUsageMetrics $usage,
    ) {
    }
}
