<?php

declare(strict_types=1);

use App\Enums\BrandCategory;
use App\Enums\BrandLanguage;
use App\Enums\GenerationGoal;
use App\Models\Brand;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\AnthropicClient;
use App\Services\Ai\BriefAiResponse;
use App\Services\Ai\Exceptions\AiRateLimitException;
use App\Services\Ai\Exceptions\AiTransientException;
use Illuminate\Support\Facades\Http;

/*
 |--------------------------------------------------------------------------
 | AnthropicClient HTTP contract test
 |--------------------------------------------------------------------------
 |
 | All HTTP is faked. We assert
 | (a) POST hits config('services.anthropic.base_url').'/v1/messages'.
 | (b) Request body has system[0].cache_control.type === 'ephemeral'.
 | (c) Request includes headers x-api-key + anthropic-version: 2023-06-01.
 | (d) 429 response throws AiRateLimitException.
 | (e) 503 response throws AiTransientException.
 | (f) 200 success returns a BriefAiResponse with rawText + populated usage.
 | (g) Persona payload (5th arg) flows into the rendered system block.
 | (h) Null persona payload produces the literal 'nessuna persona caricata'.
 |
 | We use a stub User + Brand (Models). The brand has the struttura_ricettiva
 | category so the few-shot file resolves to
 | resources/prompts/examples/struttura_ricettiva.json.
 */

beforeEach(function () {
    // Pin a deterministic API key so we can assert on it.
    config()->set('services.anthropic.api_key', 'test-key-do-not-log');
    config()->set('services.anthropic.base_url', 'https://api.anthropic.com');
    config()->set('services.anthropic.model', 'claude-sonnet-4-6');
    config()->set('services.anthropic.timeout', 60);
});

function makeFakeAnthropicSuccessPayload(): array
{
    return [
        'id' => 'msg_fake_01',
        'type' => 'message',
        'role' => 'assistant',
        'content' => [['type' => 'text', 'text' => '{"brand_summary":"Hotel di test"}']],
        'model' => 'claude-sonnet-4-6',
        'stop_reason' => 'end_turn',
        'usage' => [
            'input_tokens' => 50,
            'output_tokens' => 1200,
            'cache_creation_input_tokens' => 3800,
            'cache_read_input_tokens' => 0,
        ],
    ];
}

function makeBrandForContractTest(): Brand
{
    $user = User::factory()->create();
    return Brand::factory()
        ->for($user)
        ->create([
            'category' => BrandCategory::StrutturaRicettiva,
            'language' => BrandLanguage::It,
            'name' => 'Masseria Test',
            'city' => 'Palermo',
            'description' => 'Una struttura ricettiva di test',
        ]);
}

it('binds AiClient to AnthropicClient via the service container', function (): void {
    expect(app(AiClient::class))->toBeInstanceOf(AnthropicClient::class);
});

it('POSTs to /v1/messages with the configured base URL', function (): void {
    Http::fake([
        '*' => Http::response(makeFakeAnthropicSuccessPayload(), 200),
    ]);

    $brand = makeBrandForContractTest();
    $client = app(AiClient::class);

    $client->generateBrief(
        brand: $brand,
        sources: collect(),
        previousBrief: null,
        goal: GenerationGoal::FirstBrief,
        personaPayload: null,
    );

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/v1/messages')
            && str_starts_with($request->url(), 'https://api.anthropic.com');
    });
});

it('sends cache_control: ephemeral on the system block', function (): void {
    Http::fake([
        '*' => Http::response(makeFakeAnthropicSuccessPayload(), 200),
    ]);

    $brand = makeBrandForContractTest();
    $client = app(AiClient::class);

    $client->generateBrief(
        brand: $brand,
        sources: collect(),
        previousBrief: null,
        goal: GenerationGoal::FirstBrief,
        personaPayload: null,
    );

    Http::assertSent(function ($request) {
        $body = $request->data();

        return data_get($body, 'system.0.cache_control.type') === 'ephemeral'
            && is_string(data_get($body, 'system.0.text'))
            && data_get($body, 'model') === 'claude-sonnet-4-6'
            && data_get($body, 'max_tokens') === 4000;
    });
});

it('sends x-api-key + anthropic-version: 2023-06-01 headers', function (): void {
    Http::fake([
        '*' => Http::response(makeFakeAnthropicSuccessPayload(), 200),
    ]);

    $brand = makeBrandForContractTest();
    $client = app(AiClient::class);

    $client->generateBrief(
        brand: $brand,
        sources: collect(),
        previousBrief: null,
        goal: GenerationGoal::FirstBrief,
        personaPayload: null,
    );

    Http::assertSent(function ($request) {
        return $request->hasHeader('x-api-key', 'test-key-do-not-log')
            && $request->hasHeader('anthropic-version', '2023-06-01');
    });
});

it('throws AiRateLimitException on 429 response', function (): void {
    Http::fake([
        '*' => Http::response(['error' => ['type' => 'rate_limit_error']], 429),
    ]);

    $brand = makeBrandForContractTest();
    $client = app(AiClient::class);

    expect(fn () => $client->generateBrief(
        brand: $brand,
        sources: collect(),
        previousBrief: null,
        goal: GenerationGoal::FirstBrief,
        personaPayload: null,
    ))->toThrow(AiRateLimitException::class);
});

it('throws AiTransientException on 503 response', function (): void {
    Http::fake([
        '*' => Http::response(['error' => ['type' => 'overloaded_error']], 503),
    ]);

    $brand = makeBrandForContractTest();
    $client = app(AiClient::class);

    expect(fn () => $client->generateBrief(
        brand: $brand,
        sources: collect(),
        previousBrief: null,
        goal: GenerationGoal::FirstBrief,
        personaPayload: null,
    ))->toThrow(AiTransientException::class);
});

it('throws AiTransientException on 401 (admin-only auth error surfaces as transient)', function (): void {
    Http::fake([
        '*' => Http::response(['error' => ['type' => 'authentication_error']], 401),
    ]);

    $brand = makeBrandForContractTest();
    $client = app(AiClient::class);

    expect(fn () => $client->generateBrief(
        brand: $brand,
        sources: collect(),
        previousBrief: null,
        goal: GenerationGoal::FirstBrief,
        personaPayload: null,
    ))->toThrow(AiTransientException::class);
});

it('returns a BriefAiResponse with rawText + populated usage metrics on 200', function (): void {
    Http::fake([
        '*' => Http::response(makeFakeAnthropicSuccessPayload(), 200),
    ]);

    $brand = makeBrandForContractTest();
    $client = app(AiClient::class);

    $response = $client->generateBrief(
        brand: $brand,
        sources: collect(),
        previousBrief: null,
        goal: GenerationGoal::FirstBrief,
        personaPayload: null,
    );

    expect($response)->toBeInstanceOf(BriefAiResponse::class)
        ->and($response->rawText)->toBe('{"brand_summary":"Hotel di test"}')
        ->and($response->usage->model)->toBe('claude-sonnet-4-6')
        ->and($response->usage->inputTokens)->toBe(50)
        ->and($response->usage->outputTokens)->toBe(1200)
        ->and($response->usage->cacheWriteTokens)->toBe(3800)
        ->and($response->usage->cacheReadTokens)->toBe(0)
        ->and($response->usage->durationMs)->toBeGreaterThanOrEqual(0);
});

it('passes persona payload through into the cached system block', function (): void {
    Http::fake([
        '*' => Http::response(makeFakeAnthropicSuccessPayload(), 200),
    ]);

    $brand = makeBrandForContractTest();
    $client = app(AiClient::class);

    $persona = [
        'category' => 'struttura_ricettiva',
        'archetype' => 'ospitalita_struttura',
        'tone_descriptors' => ['caldo', 'curato'],
        'register' => 'professionale-evocativo',
        'default_avoid_mistakes' => [],
        'default_words_to_avoid' => [],
        'default_words_to_use' => [],
    ];

    $client->generateBrief(
        brand: $brand,
        sources: collect(),
        previousBrief: null,
        goal: GenerationGoal::FirstBrief,
        personaPayload: $persona,
    );

    Http::assertSent(function ($request) {
        $systemText = data_get($request->data(), 'system.0.text');
        return is_string($systemText)
            && str_contains($systemText, 'caldo')
            && str_contains($systemText, 'curato');
    });
});

it('emits the "nessuna persona caricata" sentinel when persona is null', function (): void {
    Http::fake([
        '*' => Http::response(makeFakeAnthropicSuccessPayload(), 200),
    ]);

    $brand = makeBrandForContractTest();
    $client = app(AiClient::class);

    $client->generateBrief(
        brand: $brand,
        sources: collect(),
        previousBrief: null,
        goal: GenerationGoal::FirstBrief,
        personaPayload: null,
    );

    Http::assertSent(function ($request) {
        $systemText = data_get($request->data(), 'system.0.text');
        return is_string($systemText)
            && str_contains($systemText, 'nessuna persona caricata');
    });
});
