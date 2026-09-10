<?php

declare(strict_types=1);

namespace App\Services\Ai;

use InvalidArgumentException;

/**
 * AiCostCalculator — Sonnet 4.6 + Haiku 4.5 USD cost math.
 *
 * Verified prices per [CITED: platform.claude.com/docs/en/about-claude/pricing
 * 2026-05-20]. Pure static class.
 *
 * Rates are per MILLION tokens (the Anthropic pricing page convention). The
 * formula divides the weighted sum by 1_000_000 at the end so the return
 * value is USD with sub-cent precision (stored as `decimal(8,6)`
 * `ai_usage_logs.cost_usd` by Plan 04).
 *
 *compute at write-time, store on the row (NOT a generated column) so
 * we can SUM cost_usd without recomputing every time and rates can change
 * without invalidating historical rows.
 */
final class AiCostCalculator
{
    /**
 * Rates per million tokens (USD).
 *
 * @var array<string, array<string, float>>
     */
    private const RATES = [
 // Current Sonnet tier (2026-08-26).
        'claude-sonnet-5' => [
            'input' => 2.00,
            'cache_write' => 2.50,
            'cache_read' => 0.20,
            'output' => 10.00,
        ],
 // Retained after the migration: ai_usage_logs rows written
 // before 2026-08-26 name this model, and compute() throws on any id
 // absent from this table. Removing it would break historical costing.
        'claude-sonnet-4-6' => [
            'input' => 3.00,
            'cache_write' => 3.75,
            'cache_read' => 0.30,
            'output' => 15.00,
        ],
        'claude-haiku-4-5' => [
            'input' => 1.00,
            'cache_write' => 1.25,
            'cache_read' => 0.10,
            'output' => 5.00,
        ],
    ];

    /**
 * Compute the USD cost of one Anthropic call.
 *
 * @throws InvalidArgumentException When the model is not in the verified
 * RATES table (defends against typos
 * silent miscalculation if a future
 * refactor introduces a new model).
     */
    public static function compute(
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $cacheReadTokens = 0,
        int $cacheWriteTokens = 0,
    ): float {
 // Anthropic API can return either the alias (claude-haiku-4-5) or a
 // date-versioned ID (claude-haiku-4-5-20251001). Strip the optional
 // YYYYMMDD suffix before the rate lookup so both forms work without
 // duplicating entries in the RATES table.
        $normalized = (string) preg_replace('/-\d{8}$/', '', $model);

        if (! isset(self::RATES[$normalized])) {
            throw new InvalidArgumentException("Unknown AI model: {$model}");
        }

        $r = self::RATES[$normalized];

        return (
            $inputTokens * $r['input']
            + $cacheWriteTokens * $r['cache_write']
            + $cacheReadTokens * $r['cache_read']
            + $outputTokens * $r['output']
        ) / 1_000_000;
    }
}
