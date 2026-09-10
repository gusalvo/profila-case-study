<?php

declare(strict_types=1);

use App\Enums\AiUsageStatus;
use App\Models\AiUsageLog;
use App\Models\Brand;
use App\Models\BrandBrief;
use App\Models\User;
use App\Services\Ai\AiCostCalculator;
use App\Services\Ai\AiUsageLogger;
use App\Services\Ai\AiUsageRecord;
use Tests\TestCase;

/*
 |--------------------------------------------------------------------------
 | AiUsageLogger unit tests (b Task 1)
 |--------------------------------------------------------------------------
 |
 | Covers + + (every call logs a row)
 | 1. Success record writes a row with status='success' and cost_usd that
 | matches AiCostCalculator output verbatim for the Sonnet 4.6
 | cold-cache numbers verified in (~$0.03825).
 | 2. error_rate_limit record writes cost_usd=0 (no tokens charged when
 | Anthropic rejects with 429 BEFORE billing).
 | 3. is_stub=true is honored on the row (stub fallback path).
 */
uses(TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

it('writes a success row with cost_usd matching AiCostCalculator for Sonnet cold-cache', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $brief = BrandBrief::factory()->for($brand)->create(['version' => 1]);

    $record = new AiUsageRecord(
        userId: $user->id,
        brandId: $brand->id,
        brandBriefId: $brief->id,
        pipeline: 'brief',
        model: 'claude-sonnet-4-6',
        inputTokens: 2750,
        outputTokens: 1500,
        cacheReadTokens: 0,
        cacheWriteTokens: 2000,
        status: AiUsageStatus::Success->value,
        providerErrorCode: null,
        durationMs: 6500,
        isStub: false,
    );

    (new AiUsageLogger)->log($record);

    expect(AiUsageLog::count())->toBe(1);

    $row = AiUsageLog::query()->first();
    expect($row)->not->toBeNull();
    expect($row->user_id)->toBe($user->id);
    expect($row->brand_id)->toBe($brand->id);
    expect($row->brand_brief_id)->toBe($brief->id);
    expect($row->pipeline)->toBe('brief');
    expect($row->model)->toBe('claude-sonnet-4-6');
    expect($row->input_tokens)->toBe(2750);
    expect($row->output_tokens)->toBe(1500);
    expect($row->cached_input_tokens)->toBe(0);
    expect($row->cache_write_tokens)->toBe(2000);
    expect($row->status)->toBe(AiUsageStatus::Success);
    expect($row->provider_error_code)->toBeNull();
    expect($row->duration_ms)->toBe(6500);
    expect($row->is_stub)->toBeFalse();

    $expected = AiCostCalculator::compute('claude-sonnet-4-6', 2750, 1500, 0, 2000);
    // cost_usd column is decimal(8,6) — compare with 6-dp tolerance.
    expect((float) $row->cost_usd)->toBeGreaterThan(0.0);
    expect(abs((float) $row->cost_usd - $expected))->toBeLessThan(0.0000005);
});

it('writes an error_rate_limit row with cost_usd=0 (no tokens charged on 429)', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();

    $record = new AiUsageRecord(
        userId: $user->id,
        brandId: $brand->id,
        brandBriefId: null,
        pipeline: 'brief',
        model: 'claude-sonnet-4-6',
        inputTokens: 0,
        outputTokens: 0,
        cacheReadTokens: 0,
        cacheWriteTokens: 0,
        status: AiUsageStatus::ErrorRateLimit->value,
        providerErrorCode: '429',
        durationMs: 120,
        isStub: false,
    );

    (new AiUsageLogger)->log($record);

    $row = AiUsageLog::query()->first();
    expect($row->status)->toBe(AiUsageStatus::ErrorRateLimit);
    expect($row->provider_error_code)->toBe('429');
    expect((float) $row->cost_usd)->toBe(0.0);
    expect($row->brand_brief_id)->toBeNull();
});

it('honors is_stub=true on the row (stub fallback path)', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $brief = BrandBrief::factory()->for($brand)->stub()->create(['version' => 1]);

    $record = new AiUsageRecord(
        userId: $user->id,
        brandId: $brand->id,
        brandBriefId: $brief->id,
        pipeline: 'brief',
        model: 'claude-sonnet-4-6',
        inputTokens: 0,
        outputTokens: 0,
        cacheReadTokens: 0,
        cacheWriteTokens: 0,
        status: AiUsageStatus::StubFallback->value,
        providerErrorCode: null,
        durationMs: 50,
        isStub: true,
    );

    (new AiUsageLogger)->log($record);

    $row = AiUsageLog::query()->first();
    expect($row->status)->toBe(AiUsageStatus::StubFallback);
    expect($row->is_stub)->toBeTrue();
    expect((float) $row->cost_usd)->toBe(0.0);
});
