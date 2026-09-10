<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * AiUsageRecord — readonly DTO carried into AiUsageLogger::log (b).
 *
 * Extended in with `editorialPlanId` so plan-related
 * pipelines (plan_item + regen_*) can be cost-attributed per editorial plan
 * and `isRegen` to track regen calls in ai_usage_logs.
 *
 * 15 fields (13 + editorialPlanId + isRegen).
 *
 * Construction pattern (mitigation): the orchestrator builds
 * one record per code path (success / error_*) and passes it to
 * AiUsageLogger->log() BEFORE the exception bubble or return. The Logger
 * then writes a row with cost_usd computed live via AiCostCalculator — never
 * recomputed later.
 *
 * Factory methods on AiUsageLogger build the correct record
 * per pipeline: forIdeas(), forPlanItem(), forRegen().
 *
 * BACKWARD COMPAT NOTE: `editorialPlanId` and `isRegen` are placed at the END
 * with default values to remain backward-compatible with callers
 * (BriefGenerator) that use named arguments and do not pass these fields.
 * They default to null/false respectively for the brief pipeline.
 *
 * providerRequestId would go here in v1.1 for support correlation; deferred
 * per (W1 accepted).
 */
final readonly class AiUsageRecord
{
    public function __construct(
        public int $userId,
        public ?int $brandId,
        public ?int $brandBriefId,
        public string $pipeline,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheReadTokens,
        public int $cacheWriteTokens,
        public string $status,
        public ?string $providerErrorCode,
        public int $durationMs,
        public bool $isStub,
 // additions — default values maintain backward compat.
        public ?int $editorialPlanId = null,
        public bool $isRegen = false,
    ) {
    }
}
