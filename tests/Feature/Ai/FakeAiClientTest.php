<?php

declare(strict_types=1);

use App\Enums\PlanFormat;
use App\Models\Brand;
use App\Models\BrandBrief;
use App\Models\ContentIdea;
use App\Models\PlanItem;
use App\Models\User;
use App\Services\Ai\AiUsageMetrics;
use App\Services\Ai\Exceptions\AiRateLimitException;
use App\Services\Ai\FakeAiClient;
use App\Services\Ai\IdeasAiResponse;
use App\Services\Ai\PlanItemAiResponse;
use Illuminate\Support\Collection;

/*
|--------------------------------------------------------------------------
| FakeAiClient test double verification
|--------------------------------------------------------------------------
|
| Verifies that FakeAiClient correctly
| Returns configured responses + captures input state per pipeline.
| Plays FIFO sequences for multi-call scenarios.
| Throws configured exceptions (callCount incremented before throw).
| Captures persona payload.
|
| Tests use factories but do NOT require Http::fake — FakeAiClient is
| entirely in-memory. RefreshDatabase is used (set globally in Pest.php).
*/

function makeMetrics(string $model = 'claude-haiku-4-5'): AiUsageMetrics
{
    return new AiUsageMetrics(
        model: $model,
        inputTokens: 100,
        outputTokens: 50,
        cacheReadTokens: 0,
        cacheWriteTokens: 0,
        durationMs: 123,
    );
}

function makeIdeasResponse(string $raw = '{"ideas":[]}'): IdeasAiResponse
{
    return new IdeasAiResponse(rawText: $raw, usage: makeMetrics());
}

function makePlanItemResponse(string $raw = '{"caption_short":"test"}'): PlanItemAiResponse
{
    return new PlanItemAiResponse(rawText: $raw, usage: makeMetrics('claude-sonnet-4-6'));
}

//
// generateIdeas tests
//

it('captures generateIdeas input and returns single response', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $brief = BrandBrief::factory()->for($brand)->create();

    $fake = new FakeAiClient;
    $expected = makeIdeasResponse('{"ideas":[{"theme":"test"}]}');
    $fake->setIdeasResponse($expected);

    $result = $fake->generateIdeas($brand, $brief, new Collection, 20, null);

    expect($fake->ideasCallCount)->toBe(1);
    expect($fake->lastIdeasTargetCount)->toBe(20);
    expect($result)->toBe($expected);
    expect($result->rawText)->toBe('{"ideas":[{"theme":"test"}]}');
});

it('plays generatePlanItem sequence and exhausts correctly', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $brief = BrandBrief::factory()->for($brand)->create();
    $idea = ContentIdea::factory()->for($brand)->create(['brand_brief_id' => $brief->id]);

    $fake = new FakeAiClient;
    $r1 = makePlanItemResponse('{"caption_short":"first"}');
    $r2 = makePlanItemResponse('{"caption_short":"second"}');
    $fake->setPlanItemResponses([$r1, $r2]);

    $res1 = $fake->generatePlanItem($brand, $brief, $idea, PlanFormat::Post);
    $res2 = $fake->generatePlanItem($brand, $brief, $idea, PlanFormat::Reel);

    expect($fake->planItemCallCount)->toBe(2);
    expect($res1->rawText)->toBe('{"caption_short":"first"}');
    expect($res2->rawText)->toBe('{"caption_short":"second"}');

 // Third call exceeds sequence — must throw RuntimeException.
    expect(fn () => $fake->generatePlanItem($brand, $brief, $idea, PlanFormat::Story))
        ->toThrow(\RuntimeException::class, 'sequence exhausted');
});

it('throws set throwable on generateIdeas and still increments callCount', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $brief = BrandBrief::factory()->for($brand)->create();

    $fake = new FakeAiClient;
    $fake->setIdeasThrowable(new AiRateLimitException('rate limit test'));

    expect(fn () => $fake->generateIdeas($brand, $brief, new Collection, 5))
        ->toThrow(AiRateLimitException::class, 'rate limit test');

    expect($fake->ideasCallCount)->toBe(1);
});

it('captures persona payload on generateIdeas', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $brief = BrandBrief::factory()->for($brand)->create();

    $fake = new FakeAiClient;
    $fake->setIdeasResponse(makeIdeasResponse());

    $payload = ['archetype' => 'helper', 'tone' => 'warm'];
    $fake->generateIdeas($brand, $brief, new Collection, 10, $payload);

    expect($fake->lastIdeasPersonaPayload)->toBe($payload);
    expect($fake->lastIdeasPersonaPayload['archetype'])->toBe('helper');
});

it('captures generatePlanItem format', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $brief = BrandBrief::factory()->for($brand)->create();
    $idea = ContentIdea::factory()->for($brand)->create(['brand_brief_id' => $brief->id]);

    $fake = new FakeAiClient;
    $fake->setPlanItemResponse(makePlanItemResponse());

    $fake->generatePlanItem($brand, $brief, $idea, PlanFormat::GbpPost);

    expect($fake->lastPlanItemFormat)->toBe(PlanFormat::GbpPost);
    expect($fake->planItemCallCount)->toBe(1);
});

//
// regenerateCaption / regenerateVisual / regenerateFull tests
//

it('regenerateCaption returns response and increments counter', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $brief = BrandBrief::factory()->for($brand)->create();

    $fake = new FakeAiClient;
    $expected = new PlanItemAiResponse(rawText: '{"caption_short":"regen"}', usage: makeMetrics());
    $fake->setRegenCaptionResponse($expected);

 // Build a minimal PlanItem stub without touching the DB for the fake test.
    $plan = \App\Models\EditorialPlan::factory()->for($brand)->create();
    $item = PlanItem::factory()->for($plan, 'plan')->create();

    $result = $fake->regenerateCaption($item, $brand, $brief);

    expect($fake->regenCaptionCallCount)->toBe(1);
    expect($fake->lastRegenInputItem)->toBe($item);
    expect($result->rawText)->toBe('{"caption_short":"regen"}');
});

it('regenerateVisual returns response and increments counter', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $brief = BrandBrief::factory()->for($brand)->create();
    $plan = \App\Models\EditorialPlan::factory()->for($brand)->create();
    $item = PlanItem::factory()->for($plan, 'plan')->create();

    $fake = new FakeAiClient;
    $expected = new PlanItemAiResponse(rawText: '{"visual_suggestion":"foto bellissima"}', usage: makeMetrics());
    $fake->setRegenVisualResponse($expected);

    $result = $fake->regenerateVisual($item, $brand, $brief);

    expect($fake->regenVisualCallCount)->toBe(1);
    expect($result->rawText)->toBe('{"visual_suggestion":"foto bellissima"}');
});

it('regenerateFull returns response and increments counter', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $brief = BrandBrief::factory()->for($brand)->create();
    $plan = \App\Models\EditorialPlan::factory()->for($brand)->create();
    $item = PlanItem::factory()->for($plan, 'plan')->create();

    $fake = new FakeAiClient;
    $expected = makePlanItemResponse('{"caption_short":"full regen"}');
    $fake->setRegenFullResponse($expected);

    $result = $fake->regenerateFull($item, $brand, $brief, ['archetype' => 'hero']);

    expect($fake->regenFullCallCount)->toBe(1);
    expect($result->rawText)->toBe('{"caption_short":"full regen"}');
});

it('throws configured exception on regenerateCaption', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $brief = BrandBrief::factory()->for($brand)->create();
    $plan = \App\Models\EditorialPlan::factory()->for($brand)->create();
    $item = PlanItem::factory()->for($plan, 'plan')->create();

    $fake = new FakeAiClient;
    $fake->setRegenCaptionThrowable(new AiRateLimitException('caption rate limit'));

    expect(fn () => $fake->regenerateCaption($item, $brand, $brief))
        ->toThrow(AiRateLimitException::class, 'caption rate limit');
});
