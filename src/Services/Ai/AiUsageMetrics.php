<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * AiUsageMetrics — immutable per-call usage telemetry.
 *
 * Produced by {@see AnthropicClient::generateBrief} and consumed
 * {@see \App\Services\Ai\AiUsageLogger} (Plan 04) which persists the data into
 * `ai_usage_logs` along with the computed cost.
 *
 * Field mapping from the Anthropic /v1/messages response
 * usage.input_tokens → $inputTokens
 * usage.output_tokens → $outputTokens
 * usage.cache_read_input_tokens → $cacheReadTokens
 * usage.cache_creation_input_tokens → $cacheWriteTokens
 *
 * `durationMs` is measured client-side around the HTTP::post() call.
 */
final readonly class AiUsageMetrics
{
    public function __construct(
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheReadTokens,
        public int $cacheWriteTokens,
        public int $durationMs,
    ) {
    }
}
