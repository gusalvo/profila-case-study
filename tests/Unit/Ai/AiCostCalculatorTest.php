<?php

declare(strict_types=1);

use App\Services\Ai\AiCostCalculator;

/*
 |--------------------------------------------------------------------------
 | AiCostCalculator unit tests
 |--------------------------------------------------------------------------
 |
 | Verifies the published Sonnet 4.6 and Haiku 4.5 rates
 | Pure static function; no DB; lives under tests/Unit (no RefreshDatabase).
 */

it('computes Sonnet 4.6 cold-cache cost correctly', function (): void {
 // Cold cache scenario (first call — only cache_write + output, no cache_read).
 // 7500 input tokens are written to cache (cache_write_tokens).
 // 0 cache_read because cache is empty.
 // 1200 output tokens.
 //
 // Cost = (0 * 3.00 + 7500 * 3.75 + 0 * 0.30 + 1200 * 15.00) / 1_000_000
 // = (28125 + 18000) / 1_000_000
 // = 46125 / 1_000_000
 // = 0.046125
    $cost = AiCostCalculator::compute(
        model: 'claude-sonnet-4-6',
        inputTokens: 0,
        outputTokens: 1200,
        cacheReadTokens: 0,
        cacheWriteTokens: 7500,
    );

    expect($cost)->toEqualWithDelta(0.046125, 0.000001);
});

it('computes Sonnet 4.6 warm-cache cost correctly (cache hit)', function (): void {
 // Warm cache scenario (second call — cache_read + output, no cache_write).
 // 7500 tokens are read from cache (cache_read_tokens).
 // 0 cache_write because we reuse the cached block.
 // 1200 output tokens.
 //
 // Cost = (0 * 3.00 + 0 * 3.75 + 7500 * 0.30 + 1200 * 15.00) / 1_000_000
 // = (2250 + 18000) / 1_000_000
 // = 20250 / 1_000_000
 // = 0.020250
    $cost = AiCostCalculator::compute(
        model: 'claude-sonnet-4-6',
        inputTokens: 0,
        outputTokens: 1200,
        cacheReadTokens: 7500,
        cacheWriteTokens: 0,
    );

    expect($cost)->toEqualWithDelta(0.020250, 0.000001);
});

it('computes Sonnet 4.6 plain input cost (no caching)', function (): void {
 // Plain input + output, no cache. Useful sanity check.
 // 1000 input * $3/MTok = 0.003
 // 500 output * $15/MTok = 0.0075
 // Total = 0.0105
    $cost = AiCostCalculator::compute(
        model: 'claude-sonnet-4-6',
        inputTokens: 1000,
        outputTokens: 500,
    );

    expect($cost)->toEqualWithDelta(0.0105, 0.000001);
});

it('computes Haiku 4.5 cost correctly', function (): void {
 // 1000 input * $1/MTok = 0.001
 // 500 output * $5/MTok = 0.0025
 // Total = 0.0035
    $cost = AiCostCalculator::compute(
        model: 'claude-haiku-4-5',
        inputTokens: 1000,
        outputTokens: 500,
    );

    expect($cost)->toEqualWithDelta(0.0035, 0.000001);
});

it('computes Haiku 4.5 cost with cache read and write', function (): void {
 // 200 cache_write * $1.25 = 250 / 1M = 0.000250
 // 800 cache_read * $0.10 = 80 / 1M = 0.000080
 // 300 output * $5 = 1500 / 1M = 0.001500
 // Total = 0.001830
    $cost = AiCostCalculator::compute(
        model: 'claude-haiku-4-5',
        inputTokens: 0,
        outputTokens: 300,
        cacheReadTokens: 800,
        cacheWriteTokens: 200,
    );

    expect($cost)->toEqualWithDelta(0.001830, 0.000001);
});

it('throws InvalidArgumentException on unknown model', function (): void {
    expect(fn () => AiCostCalculator::compute(
        model: 'gpt-4',
        inputTokens: 100,
        outputTokens: 100,
    ))->toThrow(InvalidArgumentException::class);
});

it('returns 0.0 when all token counts are zero', function (): void {
    $cost = AiCostCalculator::compute(
        model: 'claude-sonnet-4-6',
        inputTokens: 0,
        outputTokens: 0,
    );

    expect($cost)->toBe(0.0);
});
