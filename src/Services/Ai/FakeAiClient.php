<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\ArcStage;
use App\Enums\BrandObjective;
use App\Enums\GenerationGoal;
use App\Enums\PlanFormat;
use App\Models\Brand;
use App\Models\BrandBrief;
use App\Models\ContentIdea;
use App\Models\PlanItem;
use App\Services\Ai\SimilarOpportunitiesResponse;
use App\Services\Scraping\DTOs\ExtractedContent;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * FakeAiClient — in-memory implements AiClient (b).
 *
 * Test double for the 4 AI HTTP boundary. Used by BriefGenerator and
 * IdeaGenerator / PlanItemGenerator tests bound
 * `app()->bind(AiClient::class, fn => $fake)`.
 *
 * B3 verification hook: all inputs are captured BEFORE the throw/return so a
 * test can assert the contract holds even when the fake throws (e.g., when
 * exercising the retry exhausted path). Signatures mirror AiClient exactly.
 *
 * Three response modes per pipeline
 * set{Pipeline}Response($r): returns $r on every call.
 * set{Pipeline}Exception(\Throwable $e): throws $e on every call.
 * set{Pipeline}Responses(array $items): FIFO sequence — each item is either
 * a response DTO (return) or a Throwable (throw). When the sequence is
 * exhausted a RuntimeException fires.
 *
 * (generateBrief) — original sequence/singleResponse/callCount pattern.
 * 5 new methods: generateIdeas, generatePlanItem
 * regenerateCaption, regenerateVisual, regenerateFull; each with independent
 * counter + input capture + sequence support.
 */
final class FakeAiClient implements AiClient
{
 //
 // generateBrief
 //

    public int $callCount = 0;

 /** @var array<string, mixed>|null*/
    public ?array $lastPersonaPayload = null;

    private ?BriefAiResponse $singleResponse = null;

    private ?Throwable $singleException = null;

 /** @var list<BriefAiResponse|Throwable>*/
    private array $sequence = [];

    public function setResponse(BriefAiResponse $response): void
    {
        $this->singleResponse = $response;
        $this->singleException = null;
        $this->sequence = [];
    }

    public function setException(Throwable $exception): void
    {
        $this->singleException = $exception;
        $this->singleResponse = null;
        $this->sequence = [];
    }

    /**
 * Set a sequence of responses or exceptions, one per call (FIFO).
 *
 * @param list<BriefAiResponse|Throwable> $sequence
     */
    public function setResponseSequence(array $sequence): void
    {
        $this->sequence = $sequence;
        $this->singleResponse = null;
        $this->singleException = null;
    }

    /**
 * {@inheritDoc}
     */
    public function generateBrief(Brand $brand, Collection $sources, ?BrandBrief $previousBrief, GenerationGoal $goal, ?array $personaPayload = null): BriefAiResponse
    {
 // B3 capture — store BEFORE throwing/returning so tests can assert on
 // the persona pass-through contract even when the fake is set to throw.
        $this->lastPersonaPayload = $personaPayload;

        $index = $this->callCount;
        $this->callCount++;

        if ($this->sequence !== []) {
            if (! array_key_exists($index, $this->sequence)) {
                throw new RuntimeException('FakeAiClient: response sequence exhausted at call #'.($index + 1));
            }
            $next = $this->sequence[$index];
            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next;
        }

        if ($this->singleException !== null) {
            throw $this->singleException;
        }

        if ($this->singleResponse !== null) {
            return $this->singleResponse;
        }

        throw new RuntimeException('FakeAiClient: no response configured. Call setResponse(), setException(), or setResponseSequence() before generate().');
    }

 //
 // generateIdeas
 //

    public int $ideasCallCount = 0;

    public ?int $lastIdeasTargetCount = null;

 /** @var array<string, mixed>|null*/
    public ?array $lastIdeasPersonaPayload = null;

 /** @var list<string> Records the avoid-list passed to generateIdeas (anti-repetition).*/
    public array $lastIdeasAvoidIdeas = [];

    public ?ContentIdea $lastIdeasInputBrief = null;

    private ?IdeasAiResponse $ideasSingleResponse = null;

    private ?Throwable $ideasSingleException = null;

 /** @var list<IdeasAiResponse|Throwable>*/
    private array $ideasSequence = [];

    public function setIdeasResponse(IdeasAiResponse $response): void
    {
        $this->ideasSingleResponse = $response;
        $this->ideasSingleException = null;
        $this->ideasSequence = [];
    }

    public function setIdeasThrowable(Throwable $exception): void
    {
        $this->ideasSingleException = $exception;
        $this->ideasSingleResponse = null;
        $this->ideasSequence = [];
    }

    /**
 * @param list<IdeasAiResponse|Throwable> $responses
     */
    public function setIdeasResponses(array $responses): void
    {
        $this->ideasSequence = $responses;
        $this->ideasSingleResponse = null;
        $this->ideasSingleException = null;
    }

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
        $this->lastIdeasTargetCount = $targetCount;
        $this->lastIdeasPersonaPayload = $personaPayload;
        $this->lastIdeasAvoidIdeas = $avoidIdeas;

        $index = $this->ideasCallCount;
        $this->ideasCallCount++;

        if ($this->ideasSequence !== []) {
            if (! array_key_exists($index, $this->ideasSequence)) {
                throw new RuntimeException('FakeAiClient: ideas sequence exhausted at call #'.($index + 1));
            }
            $next = $this->ideasSequence[$index];
            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next;
        }

        if ($this->ideasSingleException !== null) {
            throw $this->ideasSingleException;
        }

        if ($this->ideasSingleResponse !== null) {
            return $this->ideasSingleResponse;
        }

        throw new RuntimeException('FakeAiClient: no ideas response configured. Call setIdeasResponse(), setIdeasThrowable(), or setIdeasResponses() first.');
    }

 //
 // generatePlanItem
 //

    public int $planItemCallCount = 0;

    public ?PlanFormat $lastPlanItemFormat = null;

 /** @var array<string, mixed>|null*/
    public ?array $lastPlanItemPersonaPayload = null;

    public ?ArcStage $lastPlanItemArcStage = null;

 /** @var list<string>*/
    public array $lastPlanItemHashtagVocab = [];

    private ?PlanItemAiResponse $planItemSingleResponse = null;

    private ?Throwable $planItemSingleException = null;

 /** @var list<PlanItemAiResponse|Throwable>*/
    private array $planItemSequence = [];

    public function setPlanItemResponse(PlanItemAiResponse $response): void
    {
        $this->planItemSingleResponse = $response;
        $this->planItemSingleException = null;
        $this->planItemSequence = [];
    }

    public function setPlanItemThrowable(Throwable $exception): void
    {
        $this->planItemSingleException = $exception;
        $this->planItemSingleResponse = null;
        $this->planItemSequence = [];
    }

    /**
 * @param list<PlanItemAiResponse|Throwable> $responses
     */
    public function setPlanItemResponses(array $responses): void
    {
        $this->planItemSequence = $responses;
        $this->planItemSingleResponse = null;
        $this->planItemSingleException = null;
    }

    /**
 * {@inheritDoc}
     */
 /** @var array<string, mixed> Captured from the last generatePlanItem call — for ContinuityDigest pass-through assertions.*/
    public array $lastContinuityDigest = [];

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
        $this->lastPlanItemFormat = $format;
        $this->lastPlanItemPersonaPayload = $personaPayload;
        $this->lastPlanItemArcStage = $arcStage;
        $this->lastPlanItemHashtagVocab = $hashtagVocab;
 // Capture BEFORE any return/throw (B3 verification hook — mirrors lastPersonaPayload pattern)
        $this->lastContinuityDigest = $continuityDigest;

        $index = $this->planItemCallCount;
        $this->planItemCallCount++;

        if ($this->planItemSequence !== []) {
            if (! array_key_exists($index, $this->planItemSequence)) {
                throw new RuntimeException('FakeAiClient: plan item sequence exhausted at call #'.($index + 1));
            }
            $next = $this->planItemSequence[$index];
            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next;
        }

        if ($this->planItemSingleException !== null) {
            throw $this->planItemSingleException;
        }

        if ($this->planItemSingleResponse !== null) {
            return $this->planItemSingleResponse;
        }

        throw new RuntimeException('FakeAiClient: no plan item response configured. Call setPlanItemResponse(), setPlanItemThrowable(), or setPlanItemResponses() first.');
    }

 //
 // regenerateCaption
 //

    public int $regenCaptionCallCount = 0;

    public ?PlanItem $lastRegenInputItem = null;

    private ?PlanItemAiResponse $regenCaptionSingleResponse = null;

    private ?Throwable $regenCaptionSingleException = null;

    public function setRegenCaptionResponse(PlanItemAiResponse $response): void
    {
        $this->regenCaptionSingleResponse = $response;
        $this->regenCaptionSingleException = null;
    }

    public function setRegenCaptionThrowable(Throwable $exception): void
    {
        $this->regenCaptionSingleException = $exception;
        $this->regenCaptionSingleResponse = null;
    }

    /**
 * {@inheritDoc}
     */
    public function regenerateCaption(PlanItem $item, Brand $brand, BrandBrief $brief, array $hashtagVocab = []): PlanItemAiResponse
    {
        $this->lastRegenInputItem = $item;
        $this->regenCaptionCallCount++;

        if ($this->regenCaptionSingleException !== null) {
            throw $this->regenCaptionSingleException;
        }

        if ($this->regenCaptionSingleResponse !== null) {
            return $this->regenCaptionSingleResponse;
        }

        throw new RuntimeException('FakeAiClient: no regen caption response configured. Call setRegenCaptionResponse() or setRegenCaptionThrowable() first.');
    }

 //
 // regenerateVisual
 //

    public int $regenVisualCallCount = 0;

    private ?PlanItemAiResponse $regenVisualSingleResponse = null;

    private ?Throwable $regenVisualSingleException = null;

    public function setRegenVisualResponse(PlanItemAiResponse $response): void
    {
        $this->regenVisualSingleResponse = $response;
        $this->regenVisualSingleException = null;
    }

    public function setRegenVisualThrowable(Throwable $exception): void
    {
        $this->regenVisualSingleException = $exception;
        $this->regenVisualSingleResponse = null;
    }

    /**
 * {@inheritDoc}
     */
    public function regenerateVisual(PlanItem $item, Brand $brand, BrandBrief $brief): PlanItemAiResponse
    {
        $this->lastRegenInputItem = $item;
        $this->regenVisualCallCount++;

        if ($this->regenVisualSingleException !== null) {
            throw $this->regenVisualSingleException;
        }

        if ($this->regenVisualSingleResponse !== null) {
            return $this->regenVisualSingleResponse;
        }

        throw new RuntimeException('FakeAiClient: no regen visual response configured. Call setRegenVisualResponse() or setRegenVisualThrowable() first.');
    }

 //
 // regenerateFull
 //

    public int $regenFullCallCount = 0;

    private ?PlanItemAiResponse $regenFullSingleResponse = null;

    private ?Throwable $regenFullSingleException = null;

    public function setRegenFullResponse(PlanItemAiResponse $response): void
    {
        $this->regenFullSingleResponse = $response;
        $this->regenFullSingleException = null;
    }

    public function setRegenFullThrowable(Throwable $exception): void
    {
        $this->regenFullSingleException = $exception;
        $this->regenFullSingleResponse = null;
    }

    /**
 * {@inheritDoc}
     */
    public function regenerateFull(PlanItem $item, Brand $brand, BrandBrief $brief, ?array $personaPayload = null, ?ArcStage $arcStage = null, array $hashtagVocab = []): PlanItemAiResponse
    {
        $this->lastRegenInputItem = $item;
        $this->regenFullCallCount++;

        if ($this->regenFullSingleException !== null) {
            throw $this->regenFullSingleException;
        }

        if ($this->regenFullSingleResponse !== null) {
            return $this->regenFullSingleResponse;
        }

        throw new RuntimeException('FakeAiClient: no regen full response configured. Call setRegenFullResponse() or setRegenFullThrowable() first.');
    }

 //
 // analyzeFirstImpression
 //

    public int $firstImpressionCallCount = 0;

    private ?FirstImpressionResponse $firstImpressionSingleResponse = null;

    private ?Throwable $firstImpressionSingleException = null;

 /** @var list<FirstImpressionResponse|Throwable>*/
    private array $firstImpressionSequence = [];

    public function setFirstImpressionResponse(FirstImpressionResponse $response): void
    {
        $this->firstImpressionSingleResponse = $response;
        $this->firstImpressionSingleException = null;
        $this->firstImpressionSequence = [];
    }

    public function setFirstImpressionThrowable(Throwable $exception): void
    {
        $this->firstImpressionSingleException = $exception;
        $this->firstImpressionSingleResponse = null;
        $this->firstImpressionSequence = [];
    }

    /**
 * @param list<FirstImpressionResponse|Throwable> $responses
     */
    public function setFirstImpressionResponses(array $responses): void
    {
        $this->firstImpressionSequence = $responses;
        $this->firstImpressionSingleResponse = null;
        $this->firstImpressionSingleException = null;
    }

    /**
 * {@inheritDoc}
     */
    public function analyzeFirstImpression(
        Brand $brand,
        ?ExtractedContent $websiteContent,
        array $socialMeta,
    ): FirstImpressionResponse {
        $index = $this->firstImpressionCallCount;
        $this->firstImpressionCallCount++;

        if ($this->firstImpressionSequence !== []) {
            if (! array_key_exists($index, $this->firstImpressionSequence)) {
                throw new RuntimeException('FakeAiClient: first impression sequence exhausted at call #'.($index + 1));
            }
            $next = $this->firstImpressionSequence[$index];
            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next;
        }

        if ($this->firstImpressionSingleException !== null) {
            throw $this->firstImpressionSingleException;
        }

        if ($this->firstImpressionSingleResponse !== null) {
            return $this->firstImpressionSingleResponse;
        }

        throw new RuntimeException('FakeAiClient: no first impression response configured. Call setFirstImpressionResponse() or setFirstImpressionThrowable() first.');
    }

 //
 // plan_logic (Logica del piano editoriale) — Haiku
 //

    public int $planLogicCallCount = 0;

    public ?Brand $lastPlanLogicBrand = null;

    public ?BrandBrief $lastPlanLogicBrief = null;

 /** @var array<string, mixed>*/
    public array $lastPlanLogicDistribution = [];

    private ?PlanLogicResponse $planLogicSingleResponse = null;

    private ?Throwable $planLogicSingleException = null;

    public function setPlanLogicResponse(PlanLogicResponse $response): void
    {
        $this->planLogicSingleResponse = $response;
        $this->planLogicSingleException = null;
    }

    public function setPlanLogicThrowable(Throwable $exception): void
    {
        $this->planLogicSingleException = $exception;
        $this->planLogicSingleResponse = null;
    }

    /**
 * {@inheritDoc}
     */
    public function generatePlanLogic(Brand $brand, BrandBrief $brief, array $distribution): PlanLogicResponse
    {
        $this->planLogicCallCount++;
        $this->lastPlanLogicBrand = $brand;
        $this->lastPlanLogicBrief = $brief;
        $this->lastPlanLogicDistribution = $distribution;

        if ($this->planLogicSingleException !== null) {
            throw $this->planLogicSingleException;
        }
        if ($this->planLogicSingleResponse !== null) {
            return $this->planLogicSingleResponse;
        }

 // Default canned response (no setup required) so existing rollup tests
 // that trigger plan_logic generation keep working unchanged.
        return new PlanLogicResponse(
            text:  'Logica del piano (fake): la distribuzione è coerente con il brief.',
            usage: new AiUsageMetrics('claude-haiku-4-5', 10, 20, 0, 0, 5),
        );
    }

 //
 // generateThemeModel
 //

    public int $themeModelCallCount = 0;

 /** @var list<string>*/
    public array $lastThemeModelThemeFocus = [];

    private ?ThemeModelAiResponse $themeModelSingleResponse = null;

    private ?Throwable $themeModelSingleException = null;

    public function setThemeModelResponse(ThemeModelAiResponse $response): void
    {
        $this->themeModelSingleResponse = $response;
        $this->themeModelSingleException = null;
    }

    public function setThemeModelThrowable(Throwable $exception): void
    {
        $this->themeModelSingleException = $exception;
        $this->themeModelSingleResponse = null;
    }

 //
 // generateSimilarBusinessOpportunities (SimilarOpportunitySynthesizer tests)
 //

    public int $similarOpportunitiesCallCount = 0;

    public ?Brand $lastSimilarOpportunitiesBrand = null;

 /** @var list<array<string, mixed>>*/
    public array $lastSimilarOpportunitiesPresenceMap = [];

    private ?SimilarOpportunitiesResponse $similarOpportunitiesSingleResponse = null;

    private ?Throwable $similarOpportunitiesSingleException = null;

    public function setSimilarOpportunitiesResponse(SimilarOpportunitiesResponse $response): void
    {
        $this->similarOpportunitiesSingleResponse = $response;
        $this->similarOpportunitiesSingleException = null;
    }

    public function setSimilarOpportunitiesThrowable(Throwable $exception): void
    {
        $this->similarOpportunitiesSingleException = $exception;
        $this->similarOpportunitiesSingleResponse = null;
    }

    public function generateSimilarBusinessOpportunities(
        Brand $brand,
        BrandBrief $brief,
        array $presenceMap,
        array $confirmedSites,
    ): SimilarOpportunitiesResponse {
        $this->similarOpportunitiesCallCount++;
        $this->lastSimilarOpportunitiesBrand = $brand;
        $this->lastSimilarOpportunitiesPresenceMap = $presenceMap;

        if ($this->similarOpportunitiesSingleException !== null) {
            throw $this->similarOpportunitiesSingleException;
        }
        if ($this->similarOpportunitiesSingleResponse !== null) {
            return $this->similarOpportunitiesSingleResponse;
        }

 // Default canned — quality_gate: true so default happy-path tests need no setup.
        return new SimilarOpportunitiesResponse(
            rawText: json_encode([
                'quality_gate'    => true,
                'osservato'       => 'Nelle homepage osservate risulta evidente...',
                'interpretazione' => 'Rispetto ai siti simili individuati, emerge uno spazio comunicativo su...',
                'opportunita'     => [
                    ['territorio' => 'Territorio', 'spazio_comunicativo' => 'Contenuti locali', 'perche' => 'Non emerge', 'come' => 'Post/reel'],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            usage: new AiUsageMetrics('claude-haiku-4-5', 10, 50, 0, 0, 100),
        );
    }

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
        $this->themeModelCallCount++;
        $this->lastThemeModelThemeFocus = $themeFocus;

        if ($this->themeModelSingleException !== null) {
            throw $this->themeModelSingleException;
        }
        if ($this->themeModelSingleResponse !== null) {
            return $this->themeModelSingleResponse;
        }

 // Default deterministic stub (no weight fields — compliance).
        $themes = [];
        foreach ($themeFocus as $theme) {
            $themes[(string) $theme] = [
                'certezza'     => 'media',
                'funnel_stage' => 'ispirazione',
                'reasoning'    => "Tema '{$theme}' — valutazione di default del fake client.",
            ];
        }

        return new ThemeModelAiResponse(
            themes: $themes,
            usage:  new AiUsageMetrics('claude-haiku-4-5', 10, 20, 0, 0, 5),
        );
    }
}
