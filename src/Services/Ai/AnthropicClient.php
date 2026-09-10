<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\ArcStage;
use App\Enums\BrandCategory;
use App\Enums\BrandLanguage;
use App\Enums\BrandObjective;
use App\Enums\GenerationGoal;
use App\Enums\PlanFormat;
use App\Models\Brand;
use App\Models\BrandBrief;
use App\Models\ContentIdea;
use App\Models\PlanItem;
use App\Services\Ai\Exceptions\AiRateLimitException;
use App\Services\Ai\Exceptions\AiTransientException;
use App\Services\Ai\SimilarOpportunitiesResponse;
use App\Services\Scraping\DTOs\ExtractedContent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * AnthropicClient — HTTP boundary to Anthropic /v1/messages.
 *
 * Wraps the Anthropic Messages API using Laravel's `Http::` facade. NO SDK
 * install — flags `anthropic-ai/sdk` as v0.x semver-unstable
 * so we minimize dependency surface and ship a thin wrapper that can be
 * swapped to the SDK with one binding change in `AppServiceProvider::boot()`.
 *
 * B3 architectural boundary: this class is a pure HTTP
 * wrapper with zero persona-resolution coupling. The `$personaPayload`
 * argument arrives pre-resolved from the caller orchestrator. The HTTP
 * boundary is strictly responsible for: render prompts → POST → map
 * errors → return DTO.
 *
 * extensions
 * generateIdeas() → claude-haiku-4-5 (Pipeline 2,; Sonnet reverted 2026-06-04 — sync timeout)
 * generatePlanItem() → claude-sonnet-4-6 (Pipeline 3)
 * regenerateCaption() → claude-haiku-4-5
 * regenerateVisual() → claude-haiku-4-5
 * regenerateFull() → claude-sonnet-4-6
 *
 * T-key-leak mitigation
 * api_key is read via config('services.anthropic.api_key') exclusively.
 * No literal env-variable name appears in this class.
 * On error paths we throw typed exceptions WITHOUT including the request
 * body or api_key in the exception message.
 * We do NOT call Log::* in this class — JsonExtractor and
 * AiUsageLogger own the observability surface.
 *
 * T-cost-blow mitigation
 * cache_control: ephemeral on system + brand_context blocks (/ pattern).
 * max_tokens tuned per pipeline (ideas=8000, plan_item=2000/4000, regen=500-2000).
 */
final class AnthropicClient implements AiClient
{
    /**
 * Per-request memoization of resolved profiles (WR-04). The provider is
 * stateless and the profile JSON is immutable per deploy, so caching
 * category value avoids re-reading/re-parsing disk on every AI call
 * including each of the 20+ per-plan-item generations in a single plan run.
 *
 * @var array<string, array<string, mixed>>
     */
    private array $verticalProfileCache = [];

 // WR-04: constructor-inject the (stateless) provider so the container
 // supplies a shared instance and it can be mocked when unit-testing this
 // class, instead of hardcoding `new VerticalProfileProvider`.
    public function __construct(
        private readonly VerticalProfileProvider $verticalProfileProvider = new VerticalProfileProvider,
    ) {}

    /**
 * Resolve (and memoize per request) the vertical_profile for a brand's
 * category. WR-04: collapses the repeated
 * `$this->verticalProfileProvider->forCategory($brand->category)` calls
 * scattered across every pipeline into a single memoized lookup.
 *
 * @return array<string, mixed>
     */
    private function verticalProfileFor(Brand $brand): array
    {
        return $this->verticalProfileCache[$brand->category->value]
            ??= $this->verticalProfileProvider->forCategory($brand->category);
    }

    /**
 * {@inheritDoc}
     */
    public function generateBrief(
        Brand $brand,
        Collection $sources,
        ?BrandBrief $previousBrief,
        GenerationGoal $goal,
        ?array $personaPayload = null,
    ): BriefAiResponse {
 // B3: pure pass-through. No persona-resolution code here. The caller
 // (BriefGenerator in Plan 04) owns persona resolution.
        $verticalProfile = $this->verticalProfileFor($brand);
        $systemBlock = PromptTemplates::brief($brand, $personaPayload, $verticalProfile);

 // Plan 04a closed the placeholder: real source serialization now
 // place via the deterministic \App\Services\Brief\SourcesSerializer::class
 // (///). The class is fully stateless; the static
 // call avoids a needless container resolve.
        $serializedSources = \App\Services\Brief\SourcesSerializer::serialize($sources);

        $userMessage = view('prompts.brief_user', [
            'brand' => $brand,
            'serializedSources' => $serializedSources,
            'previousBrief' => $previousBrief,
            'goal' => $goal,
        ])->render();

 // WR-03: delegate to the shared post() helper instead of re-implementing
 // the headers / timeout / error-status cascade / usage extraction inline.
 // post() reads the model from the passed body, so the brief model
 // (config('services.anthropic.model')) is supplied here.
        $body = [
            'model' => config('services.anthropic.model'),
            'max_tokens' => 4000,
            'system' => [
                [
                    'type' => 'text',
                    'text' => $systemBlock,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $userMessage],
                    ],
                ],
            ],
        ];

        [$rawText, $usage] = $this->post($body);

        return new BriefAiResponse(rawText: $rawText, usage: $usage);
    }

 //
 // Pipeline 2 (generateIdeas — Haiku,; Sonnet reverted 2026-06-04 — sync timeout)
 //

    /**
 * {@inheritDoc}
     */
    public function generateIdeas(
        Brand $brand,
        BrandBrief $brief,
        Collection $sources,
        int $targetCount,
        ?array $personaPayload = null,
        array $avoidIdeas = [],
    ): IdeasAiResponse {
        $verticalProfile = $this->verticalProfileFor($brand);
        $systemPrompt = PromptTemplates::ideas(
            $brand,
            $brief,
            $sources,
            $targetCount,
            $this->resolveChannelConstraints($brand),
            $this->resolveCategoryRatio($brand),
            $personaPayload,
            $avoidIdeas,
            $verticalProfile,
        );

        $body = [
 // Haiku. NOTE 2026-06-04: briefly promoted to Sonnet for quality, but
 // idea generation is a SYNCHRONOUS request (blocks the Livewire call + loader)
 // and Sonnet's latency on a 20-30 idea batch exceeded the 60s Anthropic timeout
 // (AiTransientException). Reverted to Haiku, which fits the synchronous window.
 // The $avoidIdeas anti-repetition (the actual fix for duplicate ideas) is
 // model-agnostic and stays. To revisit Sonnet, make this pipeline async/queued.
            'model' => 'claude-haiku-4-5',
            'max_tokens' => 8000,
            'system' => [
                [
                    'type' => 'text',
                    'text' => $systemPrompt,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'messages' => [
                ['role' => 'user', 'content' => "Genera {$targetCount} idee di contenuto secondo lo schema."],
            ],
        ];

        [$rawText, $usage] = $this->post($body);

        return new IdeasAiResponse(rawText: $rawText, usage: $usage);
    }

 //
 // Pipeline 3 (generatePlanItem — Sonnet)
 //

    /**
 * {@inheritDoc}
     */
    public function generatePlanItem(
        Brand $brand,
        BrandBrief $brief,
        ContentIdea $idea,
        PlanFormat $format,
        ?array $personaPayload = null,
        ?ArcStage $arcStage = null,
        array $hashtagVocab = [],
        array $continuityDigest = [],
    ): PlanItemAiResponse {
        $verticalProfile = $this->verticalProfileFor($brand);
        $systemPrompt = PromptTemplates::planItem($brand, $brief, $idea, $format, $personaPayload, $arcStage, $hashtagVocab, $continuityDigest, $verticalProfile);

 // /bilingual: it_en requires extra tokens for both language versions.
        $maxTokens = $brand->language === BrandLanguage::ItEn ? 4000 : 2000;

        $body = [
            'model' => config('services.anthropic.model'),
            'max_tokens' => $maxTokens,
            'system' => [
                [
                    'type' => 'text',
                    'text' => $systemPrompt,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'messages' => [
                ['role' => 'user', 'content' => "Genera il contenuto per il formato {$format->value} secondo lo schema."],
            ],
        ];

        [$rawText, $usage] = $this->post($body);

        return new PlanItemAiResponse(rawText: $rawText, usage: $usage);
    }

 //
 // regen_caption (Haiku)
 //

    /**
 * {@inheritDoc}
     */
    public function regenerateCaption(PlanItem $item, Brand $brand, BrandBrief $brief, array $hashtagVocab = []): PlanItemAiResponse
    {
        $verticalProfile = $this->verticalProfileFor($brand);
        $systemPrompt = PromptTemplates::regenCaption($item, $brand, $brief, $hashtagVocab, $verticalProfile);

        $body = [
            'model' => 'claude-haiku-4-5',
            'max_tokens' => 1500,
            'system' => [
                [
                    'type' => 'text',
                    'text' => $systemPrompt,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'messages' => [
                ['role' => 'user', 'content' => 'Rigenera i campi caption secondo lo schema.'],
            ],
        ];

        [$rawText, $usage] = $this->post($body);

        return new PlanItemAiResponse(rawText: $rawText, usage: $usage);
    }

 //
 // regen_visual (Haiku)
 //

    /**
 * {@inheritDoc}
     */
    public function regenerateVisual(PlanItem $item, Brand $brand, BrandBrief $brief): PlanItemAiResponse
    {
        $verticalProfile = $this->verticalProfileFor($brand);
        $systemPrompt = PromptTemplates::regenVisual($item, $brand, $brief, $verticalProfile);

        $body = [
            'model' => 'claude-haiku-4-5',
            'max_tokens' => 500,
            'system' => [
                [
                    'type' => 'text',
                    'text' => $systemPrompt,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'messages' => [
                ['role' => 'user', 'content' => 'Rigenera visual_suggestion secondo lo schema.'],
            ],
        ];

        [$rawText, $usage] = $this->post($body);

        return new PlanItemAiResponse(rawText: $rawText, usage: $usage);
    }

 //
 // regen_full (Sonnet)
 //

    /**
 * {@inheritDoc}
     */
    public function regenerateFull(PlanItem $item, Brand $brand, BrandBrief $brief, ?array $personaPayload = null, ?ArcStage $arcStage = null, array $hashtagVocab = []): PlanItemAiResponse
    {
 // regenerateFull reuses plan_item.blade.php with the existing item data
 // as the ContentIdea context (loaded from the item's idea relation or
 // rebuilt inline from item fields).
        $idea = $item->idea ?? $this->buildIdeaFromItem($item);

 // regenerateFull reuses plan_item.blade.php; continuityDigest passed as []
 // regen does not rebuild history context (digest is generation-side only).
        $verticalProfile = $this->verticalProfileFor($brand);
        $systemPrompt = PromptTemplates::planItem($brand, $brief, $idea, $item->format ?? PlanFormat::Post, $personaPayload, $arcStage, $hashtagVocab, [], $verticalProfile);

 // /bilingual: it_en requires extra tokens.
        $maxTokens = $brand->language === BrandLanguage::ItEn ? 4000 : 2000;

        $body = [
            'model' => config('services.anthropic.model'),
            'max_tokens' => $maxTokens,
            'system' => [
                [
                    'type' => 'text',
                    'text' => $systemPrompt,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'messages' => [
                ['role' => 'user', 'content' => 'Rigenera il contenuto completo secondo lo schema.'],
            ],
        ];

        [$rawText, $usage] = $this->post($body);

        return new PlanItemAiResponse(rawText: $rawText, usage: $usage);
    }

 //
 // analyzeFirstImpression (Haiku)
 //

    /**
 * {@inheritDoc}
     */
    public function analyzeFirstImpression(
        Brand $brand,
        ?ExtractedContent $websiteContent,
        array $socialMeta,
    ): FirstImpressionResponse {
        $verticalProfile = $this->verticalProfileFor($brand);
        $systemPrompt = PromptTemplates::firstImpression($brand, $verticalProfile);

 // T-7.2 / WR-05: untrusted scraped/social content is XML-delimited in the
 // user message via the dedicated user-only partial. The cached system
 // prefix (schema + role) is built once by PromptTemplates::firstImpression()
 // above — the user message no longer re-renders it, removing the
 // double-render and its cache-drift risk.
        $userMessage = view('prompts.first_impression_user', [
            'websiteContent' => $websiteContent,
            'socialMeta'     => $socialMeta,
        ])->render();

        $body = [
            'model'      => config('services.anthropic.model'),    // routing promoted to Sonnet (14-02)
            'max_tokens' => 4000,
            'system'     => [
                [
                    'type'          => 'text',
                    'text'          => $systemPrompt,
                    'cache_control' => ['type' => 'ephemeral'],    // T-cost-blow mitigation
                ],
            ],
            'messages' => [
                ['role' => 'user', 'content' => $userMessage],
            ],
        ];

        [$rawText, $usage] = $this->post($body);    // shared helper (lines 379–440)

        return new FirstImpressionResponse(rawText: $rawText, usage: $usage);
    }

    /**
 * Generate the "Logica del piano editoriale" (plan_logic) — Haiku.
 *
 * System block (cacheable) = brand/brief grounding + anti-hallucination
 * rules. User message (per-plan, not cached) = the actual distribution.
 *
 * @param array<string, mixed> $distribution
     */
    public function generatePlanLogic(
        Brand $brand,
        BrandBrief $brief,
        array $distribution,
    ): PlanLogicResponse {
        $verticalProfile = $this->verticalProfileFor($brand);
        $systemPrompt = PromptTemplates::planLogic($brand, $brief, $verticalProfile);

        $body = [
            'model'      => 'claude-haiku-4-5',    // cheap — one short paragraph
            'max_tokens' => 600,
            'system'     => [
                [
                    'type'          => 'text',
                    'text'          => $systemPrompt,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'messages' => [
                ['role' => 'user', 'content' => $this->planLogicDistributionMessage($distribution)],
            ],
        ];

        [$rawText, $usage] = $this->post($body);

        return new PlanLogicResponse(text: trim($rawText), usage: $usage);
    }

 //
 // generateSimilarBusinessOpportunities (Haiku)
 //

    /**
 * {@inheritDoc}
     */
    public function generateSimilarBusinessOpportunities(
        Brand $brand,
        BrandBrief $brief,
        array $presenceMap,
        array $confirmedSites,
    ): SimilarOpportunitiesResponse {
        $verticalProfile = $this->verticalProfileFor($brand);
        $systemPrompt = PromptTemplates::similarBusinesses($brand, $brief, $verticalProfile);

        $userMessage = $this->buildSimilarBusinessUserMessage($presenceMap, $confirmedSites);

        $body = [
 // Haiku — same timeout discipline as generateIdeas.
 // Sonnet risks the 60s sync timeout on this synchronous call (memory: project_ideas_history_aware_sonnet).
            'model'      => 'claude-haiku-4-5',
            'max_tokens' => 1200,
            'system'     => [
                [
                    'type'          => 'text',
                    'text'          => $systemPrompt,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'messages' => [
                ['role' => 'user', 'content' => $userMessage],
            ],
        ];

        [$rawText, $usage] = $this->post($body);

        return new SimilarOpportunitiesResponse(rawText: $rawText, usage: $usage);
    }

    /**
 * Build the per-run user message for the similar-activities synthesis.
 *
 * Serializes the deterministic presence map (TerritoryPresenceMapper::map() output)
 * and confirmed-site summaries (title/meta only — GR anti-contamination
 *never pass raw competitor body text to the AI).
 *
 * @param list<array{name: string, level: string, reason: string}> $presenceMap
 * @param list<array{url: string, title: string, meta: string}> $confirmedSites
     */
    private function buildSimilarBusinessUserMessage(array $presenceMap, array $confirmedSites): string
    {
        $lines = [];

 // Territory presence map from TerritoryPresenceMapper
        $lines[] = '<territory_map>';
        foreach ($presenceMap as $entry) {
            $name   = $entry['name']   ?? '';
            $level  = $entry['level']  ?? '';
            $reason = $entry['reason'] ?? '';
            $lines[] = "  {$name}: {$level}" . ($reason !== '' ? " — {$reason}" : '');
        }
        $lines[] = '</territory_map>';

        $lines[] = '';

 // Confirmed-site summaries — titles and meta only, never raw body text
        $lines[] = '<siti_simili>';
        foreach ($confirmedSites as $site) {
            $url   = $site['url']   ?? '';
            $title = $site['title'] ?? '';
            $meta  = $site['meta']  ?? '';
            $lines[] = "  URL: {$url}";
            if ($title !== '') {
                $lines[] = "  Titolo: {$title}";
            }
            if ($meta !== '') {
                $lines[] = "  Meta: {$meta}";
            }
            $lines[] = '';
        }
        $lines[] = '</siti_simili>';

        $lines[] = '';
        $lines[] = 'Produci ora il JSON di sintesi seguendo le istruzioni del system.';

        return implode("\n", $lines);
    }

 //
 // generateThemeModel (Haiku, grounded)
 //

    /**
 * {@inheritDoc}
     */
    public function generateThemeModel(
        Brand $brand,
        BrandBrief $brief,
        array $distribution,
        array $themeFocus,
        ?BrandObjective $objective,
    ): ThemeModelAiResponse {
        $verticalProfile = $this->verticalProfileFor($brand);
        $systemPrompt = PromptTemplates::themeModel($brand, $brief, $objective, $verticalProfile);

 // User message: grounded distribution + theme focus list.
        $lines = ['Temi da valutare:'];
        foreach ($themeFocus as $theme) {
            $lines[] = '- '.$theme;
        }
        $lines[] = '';
        $lines[] = 'Distribuzione attuale del piano:';
        if (! empty($distribution['themes'])) {
            $parts = [];
            foreach ($distribution['themes'] as $name => $count) {
                $parts[] = is_int($name) ? (string) $count : $name.' ('.$count.')';
            }
            $lines[] = 'Temi: '.implode(', ', $parts);
        }
        $lines[] = '';
        $lines[] = 'Rispondi con JSON puro. Per ogni tema elencato, emetti un oggetto con chiave = label del tema e valore = {certezza, funnel_stage, reasoning}. ZERO numeri percentuale.';

        $userMessage = implode("\n", $lines);

        $body = [
            'model'      => 'claude-haiku-4-5',    // cheap strategic assessment
            'max_tokens' => 1200,
            'system'     => [
                [
                    'type'          => 'text',
                    'text'          => $systemPrompt,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'messages' => [
                ['role' => 'user', 'content' => $userMessage],
            ],
        ];

        [$rawText, $usage] = $this->post($body);

 // Decode the AI's JSON into the structured DTO.
 // JsonExtractor strips markdown fences and extracts the JSON object.
        $decoded = JsonExtractor::extract($rawText);

 // The AI returns a map of theme => {certezza, funnel_stage, reasoning}.
 // Validate and normalise — any malformed entry gets a safe default.
        $themes = [];
        foreach ($decoded as $themeLabel => $entry) {
            if (! is_string($themeLabel) || ! is_array($entry)) {
                continue;
            }
            $themes[$themeLabel] = [
                'certezza'     => is_string($entry['certezza'] ?? null) ? $entry['certezza'] : 'media',
                'funnel_stage' => is_string($entry['funnel_stage'] ?? null) ? $entry['funnel_stage'] : 'ispirazione',
                'reasoning'    => is_string($entry['reasoning'] ?? null) ? $entry['reasoning'] : '',
            ];
        }

        return new ThemeModelAiResponse(themes: $themes, usage: $usage);
    }

    /**
 * Render the plan distribution as a compact Italian text block for the
 * plan_logic user message. Only includes sections that have data.
 *
 * @param array<string, mixed> $distribution
     */
    private function planLogicDistributionMessage(array $distribution): string
    {
        $lines = ['Distribuzione reale dei contenuti di questo piano:'];

        if (! empty($distribution['total'])) {
            $lines[] = 'Totale contenuti: '.$distribution['total'];
        }
 // Exact count of distinct editorial focuses (categories) — if the model
 // states a number of focuses it MUST use this one, never an invented numeral.
        if (! empty($distribution['categories'])) {
            $lines[] = 'Focus editoriali distinti: '.count($distribution['categories']);
        }
        foreach (['themes' => 'Temi', 'categories' => 'Categorie', 'formats' => 'Formati', 'channels' => 'Canali', 'objectives' => 'Obiettivi'] as $key => $label) {
            $section = $distribution[$key] ?? [];
            if (empty($section)) {
                continue;
            }
            $parts = [];
            foreach ($section as $name => $count) {
 // List ([v]) → just the value; map ([name => count]) → "name (count)".
                $parts[] = is_int($name) ? (string) $count : $name.' ('.$count.')';
            }
            $lines[] = $label.': '.implode(', ', $parts);
        }

        $lines[] = 'Scrivi ora il paragrafo "Logica del piano editoriale" seguendo le regole del system.';

        return implode("\n", $lines);
    }

 //
 // Shared HTTP request helper
 //

    /**
 * Execute a POST to Anthropic /v1/messages with the given body.
 *
 * Extracts shared HTTP logic: headers, timeout, error status cascade.
 * Returns [$rawText, $usage] as a 2-tuple.
 *
 * @param array<string, mixed> $body
 * @return array{0: string, 1: AiUsageMetrics}
 *
 * @throws AiRateLimitException
 * @throws AiTransientException
     */
    private function post(array $body): array
    {
        $baseUrl = config('services.anthropic.base_url');
        $apiKey = config('services.anthropic.api_key');
        $timeout = (int) config('services.anthropic.timeout');

        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
                ->timeout($timeout)
                ->post($baseUrl.'/v1/messages', $body);
        } catch (ConnectionException $e) {
            throw new AiTransientException(
                'Anthropic transport error (timeout or network)',
                0,
                $e,
            );
        } catch (Throwable $e) {
            throw new AiTransientException(
                'Anthropic transport error',
                0,
                $e,
            );
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response->status() === 429) {
            throw new AiRateLimitException('Anthropic rate limit reached (HTTP 429)');
        }

        if ($response->status() === 401) {
            throw new AiTransientException('Anthropic authentication error (HTTP 401)');
        }

        if (! $response->successful()) {
            throw new AiTransientException('Anthropic upstream error (HTTP '.$response->status().')');
        }

        $rawText = (string) $response->json('content.0.text', '');

        $usage = new AiUsageMetrics(
            model: (string) ($body['model'] ?? ''),
            inputTokens: (int) $response->json('usage.input_tokens', 0),
            outputTokens: (int) $response->json('usage.output_tokens', 0),
            cacheReadTokens: (int) $response->json('usage.cache_read_input_tokens', 0),
            cacheWriteTokens: (int) $response->json('usage.cache_creation_input_tokens', 0),
            durationMs: $durationMs,
        );

        return [$rawText, $usage];
    }

 //
 // Private helpers
 //

    /**
 * Build a transient ContentIdea-like object from a PlanItem for regenerateFull.
 *
 * regenerateFull needs a ContentIdea to pass to PromptTemplates::planItem.
 * When the item's idea relation is not loaded, we build an anonymous class
 * that satisfies the template's property accesses.
     */
    private function buildIdeaFromItem(PlanItem $item): ContentIdea
    {
        $idea = new ContentIdea;
        $idea->theme = $item->theme ?? '';
        $idea->idea_text = $item->idea_text ?? '';
        $idea->objective = $item->objective ?? '';

        return $idea;
    }

    /**
 * Resolve the channel constraints for a brand (hint for AI + #6).
 *
 * #6 fix: previously returned a hardcoded all-six list, so the AI could
 * suggest channels (linkedin/threads/x) the brand never uses. Now constrained
 * to the brand's DETECTED channels ({@see Brand::detectedChannels()}) — Profila
 * does not propose content for platforms the client isn't on. Channels the
 * brand should *consider* adopting are surfaced separately as advice
 * ({@see \App\Services\Plans\ChannelAdvisor}), never as generated content.
 *
 * @return array<string>
     */
    private function resolveChannelConstraints(Brand $brand): array
    {
        return $brand->detectedChannels();
    }

    /**
 * Resolve the category ratio hint for a brand (format distribution).
 *
 * Pure function on BrandCategory. Used by generateIdeas to inform the
 * AI about the ideal format distribution for this brand's category.
 *
 * @return array<string, int>
     */
    private function resolveCategoryRatio(Brand $brand): array
    {
        return match ($brand->category) {
            BrandCategory::StrutturaRicettiva => ['post' => 35, 'reel' => 30, 'story' => 20, 'gbp_post' => 15],
            BrandCategory::RistoranteFood     => ['post' => 35, 'reel' => 30, 'story' => 20, 'gbp_post' => 15],
            BrandCategory::Beauty             => ['post' => 35, 'reel' => 25, 'story' => 25, 'gbp_post' => 15],
            BrandCategory::TourEsperienze     => ['carousel' => 30, 'reel' => 35, 'story' => 20, 'gbp_post' => 15],
            BrandCategory::Professionista     => ['post' => 50, 'reel' => 15, 'story' => 10, 'gbp_post' => 25],
            default                           => ['post' => 40, 'reel' => 25, 'story' => 20, 'gbp_post' => 15],
        };
    }
}
