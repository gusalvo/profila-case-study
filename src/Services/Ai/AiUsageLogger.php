<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AiUsageStatus;
use App\Models\AiUsageLog;
use App\Models\Brand;
use App\Models\EditorialPlan;
use App\Models\PlanItem;

/**
 * AiUsageLogger — write one ai_usage_logs row per AI call (b).
 *
 * Verbatim from of: every call (success
 * 5 typed errors + stub_fallback) writes a row so cost monitoring catches
 * each failure mode.
 *
 * Mitigates (forgetting to log on exception paths)
 * being the single sink — the orchestrator calls log in every catch + the
 * success path.
 *
 * factory methods
 * forIdeas — pipeline='ideas' (is_regen flag, no plan_id)
 * forPlanItem — pipeline='plan_item', editorial_plan_id set
 * forRegen — pipeline='regen_caption'|'regen_visual'|'regen_full'
 *
 * cost_usd is computed at write-time via {@see AiCostCalculator::compute}
 *. Storing the decimal lets future SUM aggregations
 * skip recomputation; rates can change without invalidating historical rows.
 */
final class AiUsageLogger
{
    /**
 * Write one row from the prepared record.
 *
 * Single point of mutation for `ai_usage_logs` from the application code
 * (factories use the normal Eloquent path). Never returns the model
 * the orchestrator does not need the row id.
     */
    public function log(AiUsageRecord $record): void
    {
        AiUsageLog::create([
            'user_id' => $record->userId,
            'brand_id' => $record->brandId,
            'brand_brief_id' => $record->brandBriefId,
            'editorial_plan_id' => $record->editorialPlanId,
            'pipeline' => $record->pipeline,
            'model' => $record->model,
            'input_tokens' => $record->inputTokens,
            'output_tokens' => $record->outputTokens,
            'cached_input_tokens' => $record->cacheReadTokens,
            'cache_write_tokens' => $record->cacheWriteTokens,
            'cost_usd' => AiCostCalculator::compute(
                $record->model,
                $record->inputTokens,
                $record->outputTokens,
                $record->cacheReadTokens,
                $record->cacheWriteTokens,
            ),
            'status' => $record->status,
            'provider_error_code' => $record->providerErrorCode,
            'duration_ms' => $record->durationMs,
            'is_stub' => $record->isStub,
            'is_regen' => $record->isRegen,
        ]);
    }

    /**
 * Build an AiUsageRecord for the ideas pipeline (Pipeline 2 — Haiku).
 *
 * `editorial_plan_id` is null for ideas generation — ideas are not yet
 * bound to a plan at generation time.
 *
 * @param AiUsageMetrics $metrics Token counts from the AI response.
 * @param AiUsageStatus $status Outcome of this call.
 * @param bool $isRegen True when re-generating ideas.
 * @param string|null $providerErrorCode Error code from Anthropic, if any.
     */
    public function forIdeas(
        \App\Models\Brand $brand,
        AiUsageMetrics $metrics,
        AiUsageStatus $status,
        bool $isRegen = false,
        ?string $providerErrorCode = null,
    ): AiUsageRecord {
        return new AiUsageRecord(
            userId: $brand->user_id,
            brandId: $brand->id,
            brandBriefId: null,
            editorialPlanId: null,
            pipeline: 'ideas',
            model: 'claude-haiku-4-5',
            inputTokens: $metrics->inputTokens,
            outputTokens: $metrics->outputTokens,
            cacheReadTokens: $metrics->cacheReadTokens,
            cacheWriteTokens: $metrics->cacheWriteTokens,
            status: $status->value,
            providerErrorCode: $providerErrorCode,
            durationMs: $metrics->durationMs,
            isStub: false,
            isRegen: $isRegen,
        );
    }

    /**
 * Build an AiUsageRecord for the first_impression pipeline (Haiku).
 *
 * Mirrors forIdeas (pipeline='ideas') with first_impression pipeline.
 * `brand_brief_id` is null — no BrandBrief exists at stage 1.
 * `editorial_plan_id` is null — not plan-bound.
 * 'first_impression' is 16 chars — fits VARCHAR(30), no migration needed.
 *
 * @param Brand $brand The brand being onboarded.
 * @param AiUsageMetrics $metrics Token counts from the Haiku response.
 * @param AiUsageStatus $status Outcome of this call.
 * @param string|null $providerErrorCode Error code from Anthropic, if any.
     */
    public function forFirstImpression(
        Brand $brand,
        AiUsageMetrics $metrics,
        AiUsageStatus $status,
        ?string $providerErrorCode = null,
    ): AiUsageRecord {
        return new AiUsageRecord(
            userId: $brand->user_id,
            brandId: $brand->id,
            brandBriefId: null,
            pipeline: 'first_impression',    // 16 chars — fits VARCHAR(30)
            model: config('services.anthropic.model'),      // promoted from Haiku to Sonnet
            inputTokens: $metrics->inputTokens,
            outputTokens: $metrics->outputTokens,
            cacheReadTokens: $metrics->cacheReadTokens,
            cacheWriteTokens: $metrics->cacheWriteTokens,
            status: $status->value,
            providerErrorCode: $providerErrorCode,
            durationMs: $metrics->durationMs,
            isStub: false,
        );
    }

    /**
 * Build an AiUsageRecord for the plan_logic pipeline (Haiku).
 *
 * `editorial_plan_id` is set for cost-per-plan analytics.
     */
    public function forPlanLogic(
        EditorialPlan $plan,
        AiUsageMetrics $metrics,
        AiUsageStatus $status,
        ?string $providerErrorCode = null,
    ): AiUsageRecord {
        return new AiUsageRecord(
            userId: $plan->brand->user_id,
            brandId: $plan->brand_id,
            brandBriefId: null,
            editorialPlanId: $plan->id,
            pipeline: 'plan_logic',
            model: 'claude-haiku-4-5',
            inputTokens: $metrics->inputTokens,
            outputTokens: $metrics->outputTokens,
            cacheReadTokens: $metrics->cacheReadTokens,
            cacheWriteTokens: $metrics->cacheWriteTokens,
            status: $status->value,
            providerErrorCode: $providerErrorCode,
            durationMs: $metrics->durationMs,
            isStub: false,
        );
    }

    /**
 * Build an AiUsageRecord for the similar_opportunities pipeline (Haiku).
 *
 * Brand-level (not plan-bound): editorialPlanId is null. 'similar_opportunities'
 * = 22 chars — fits VARCHAR(30) column (verified Assumption A1).
     */
    public function forSimilarOpportunities(
        Brand $brand,
        AiUsageMetrics $metrics,
        AiUsageStatus $status,
        ?string $providerErrorCode = null,
    ): AiUsageRecord {
        return new AiUsageRecord(
            userId:            $brand->user_id,
            brandId:           $brand->id,
            brandBriefId:      null,
            pipeline:          'similar_opportunities',  // 22 chars ≤ VARCHAR(30)
            model:             'claude-haiku-4-5',
            inputTokens:       $metrics->inputTokens,
            outputTokens:      $metrics->outputTokens,
            cacheReadTokens:   $metrics->cacheReadTokens,
            cacheWriteTokens:  $metrics->cacheWriteTokens,
            status:            $status->value,
            providerErrorCode: $providerErrorCode,
            durationMs:        $metrics->durationMs,
            isStub:            false,
            editorialPlanId:   null,     // brand-level, not plan-bound
            isRegen:           false,
        );
    }

    /**
 * Build an AiUsageRecord for the plan_item pipeline (Pipeline 3 — Sonnet).
 *
 * `editorial_plan_id` is set for cost-per-plan analytics.
 *
 * @param EditorialPlan $plan The plan this item belongs to.
 * @param PlanItem $item The specific plan item generated.
 * @param AiUsageMetrics $metrics Token counts from the AI response.
 * @param AiUsageStatus $status Outcome of this call.
 * @param string|null $providerErrorCode Error code from Anthropic, if any.
     */
    public function forPlanItem(
        EditorialPlan $plan,
        PlanItem $item,
        AiUsageMetrics $metrics,
        AiUsageStatus $status,
        ?string $providerErrorCode = null,
    ): AiUsageRecord {
        return new AiUsageRecord(
            userId: $plan->brand->user_id,
            brandId: $plan->brand_id,
            brandBriefId: null,
            editorialPlanId: $plan->id,
            pipeline: 'plan_item',
            model: config('services.anthropic.model'),
            inputTokens: $metrics->inputTokens,
            outputTokens: $metrics->outputTokens,
            cacheReadTokens: $metrics->cacheReadTokens,
            cacheWriteTokens: $metrics->cacheWriteTokens,
            status: $status->value,
            providerErrorCode: $providerErrorCode,
            durationMs: $metrics->durationMs,
            isStub: false,
            isRegen: false,
        );
    }

    /**
 * Build an AiUsageRecord for a regen pipeline.
 *
 * Model routing
 * scope='caption' → Haiku (claude-haiku-4-5)
 * scope='visual' → Haiku (claude-haiku-4-5)
 * scope='full' → Sonnet (claude-sonnet-4-6)
 *
 * @param PlanItem $item The item being regenerated.
 * @param string $scope 'caption' | 'visual' | 'full'.
 * @param AiUsageMetrics $metrics Token counts from the AI response.
 * @param AiUsageStatus $status Outcome of this call.
 * @param string|null $providerErrorCode Error code from Anthropic, if any.
     */
    public function forRegen(
        PlanItem $item,
        string $scope,
        AiUsageMetrics $metrics,
        AiUsageStatus $status,
        ?string $providerErrorCode = null,
    ): AiUsageRecord {
 //Haiku for partial regen, Sonnet for full regen.
        $model = $scope === 'full' ? config('services.anthropic.model') : 'claude-haiku-4-5';
        $pipeline = 'regen_'.$scope;
 // plan_id resolves the editorial plan FK.
        $editorialPlanId = $item->plan_id;

        return new AiUsageRecord(
            userId: $item->plan->brand->user_id,
            brandId: $item->plan->brand_id,
            brandBriefId: null,
            editorialPlanId: $editorialPlanId,
            pipeline: $pipeline,
            model: $model,
            inputTokens: $metrics->inputTokens,
            outputTokens: $metrics->outputTokens,
            cacheReadTokens: $metrics->cacheReadTokens,
            cacheWriteTokens: $metrics->cacheWriteTokens,
            status: $status->value,
            providerErrorCode: $providerErrorCode,
            durationMs: $metrics->durationMs,
            isStub: false,
            isRegen: true,
        );
    }
}
