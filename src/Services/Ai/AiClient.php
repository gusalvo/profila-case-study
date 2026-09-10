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
use App\Services\Ai\Exceptions\AiInvalidJsonException;
use App\Services\Ai\Exceptions\AiRateLimitException;
use App\Services\Ai\Exceptions\AiSchemaInvalidException;
use App\Services\Ai\Exceptions\AiTransientException;
use App\Services\Scraping\DTOs\ExtractedContent;
use Illuminate\Support\Collection;

/**
 * AiClient — AI HTTP boundary contract (04-04).
 *
 * 6 pipeline methods: generateBrief, generateIdeas + generatePlanItem
 * 3 regenerate scopes. The interface is intentionally per-pipeline
 * so each call site keeps type-safe inputs/outputs instead of a generic
 * `call($prompt, $context)` blob.
 *
 * Implementation lives in {@see \App\Services\Ai\AnthropicClient}. The binding
 * is registered in `AppServiceProvider::boot()`.
 *
 * B3 architectural boundary: the persona fallback decision is
 * owned EXCLUSIVELY by the calling orchestrator (BriefGenerator / IdeaGenerator
 * / PlanItemGenerator). Implementations of this interface MUST NOT call
 * PersonaResolver themselves — `$personaPayload` arrives pre-resolved from the
 * caller. AnthropicClient is a thin HTTP wrapper with zero
 * PersonaResolver coupling.
 *
 * Model routing
 * Haiku (claude-haiku-4-5) → generateIdeas, regenerateCaption, regenerateVisual
 * analyzeFirstImpression (stage 1)
 * Sonnet (claude-sonnet-4-6) → generateBrief, generatePlanItem, regenerateFull
 */
interface AiClient
{
    /**
 * Generate a structured brand brief.
 *
 * @param Brand $brand The brand to analyze.
 * @param Collection $sources Pre-filtered active sources (transitive Layer 1).
 * @param BrandBrief|null $previousBrief Last confirmed version, for regen continuity. Null on first_brief.
 * @param GenerationGoal $goal Enum: FirstBrief | RegenerateWithChanges.
 * @param array<string, mixed>|null $personaPayload The resolved persona archetype JSON payload
 * `PersonaResolver::resolve()` (Plan 04), or `null`
 * if the brand has >=2 active tone_rule sources
 *. The implementation MUST NOT call
 * PersonaResolver itself — assigns
 * that responsibility exclusively to BriefGenerator
 * (Plan 04).
 *
 * @throws AiRateLimitException On 429 after upstream retry exhausted.
 * @throws AiTransientException On 5xx / network / timeout / 401.
 * @throws AiInvalidJsonException On JSON parse retry exhausted (raised by JsonExtractor wrapper).
 * @throws AiSchemaInvalidException On schema fail after JSON parse OK (raised by validator wrapper).
     */
    public function generateBrief(Brand $brand, Collection $sources, ?BrandBrief $previousBrief, GenerationGoal $goal, ?array $personaPayload = null): BriefAiResponse;

    /**
 * Generate a batch of content ideas for a brand (Pipeline 2 — Haiku).
 *
 * @param Brand $brand The brand to generate ideas for.
 * @param BrandBrief $brief The confirmed brief (pre-condition).
 * @param Collection $sources Pre-filtered active sources for context.
 * @param int $targetCount Number of ideas requested.
 * @param array<string, mixed>|null $personaPayload Pre-resolved persona, or null.
 * @param list<string> $avoidIdeas Existing idea texts for this brand (any
 * status, incl. consumed by past plans) that
 * the model MUST NOT repeat — fresh angles only.
 * Trailing/optional for back-compat.
 *
 * @throws AiRateLimitException On 429 after upstream retry exhausted.
 * @throws AiTransientException On 5xx / network / timeout / 401.
 * @throws AiInvalidJsonException On JSON parse retry exhausted.
 * @throws AiSchemaInvalidException On schema fail after JSON parse OK.
     */
    public function generateIdeas(
        Brand $brand,
        BrandBrief $brief,
        Collection $sources,
        int $targetCount,
        ?array $personaPayload = null,
        array $avoidIdeas = [],
    ): IdeasAiResponse;

    /**
 * Generate a single PlanItem content card for a ContentIdea (Pipeline 3 — Sonnet).
 *
 * @param Brand $brand The brand to generate for.
 * @param BrandBrief $brief The confirmed brief.
 * @param ContentIdea $idea The idea to expand into a plan item.
 * @param PlanFormat $format The format to produce.
 * @param array<string, mixed>|null $personaPayload Pre-resolved persona, or null.
 * @param ArcStage|null $arcStage The item's narrative arc stage (
 *). Null = arc-unaware generation (backward
 * compatible). Pass via PlanArcMapper::stageFor().
 * @param list<string> $hashtagVocab Brand-derived hashtag whitelist (
 *). Empty = no vocab constraint.
 * Pass via HashtagVocabularyBuilder::for().
 * @param array<string, mixed> $continuityDigest PHP-computed count digest from ContinuityDigest::build()
 *. Empty = no history context
 * (back-compatible). Contains ONLY counts/labels
 * no raw captions. Passed through to the
 * plan_item prompt as factual anti-monotony context.
 *
 * @throws AiRateLimitException
 * @throws AiTransientException
 * @throws AiInvalidJsonException
 * @throws AiSchemaInvalidException
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
    ): PlanItemAiResponse;

    /**
 * Regenerate only caption fields for a PlanItem (Haiku).
 *
 * Preserves theme, idea_text, format, visual_suggestion. Returns
 * a PlanItemAiResponse with rawText containing only
 * {caption_short, caption_long, cta, hashtags}.
 *
 * /: caption regen EMITS hashtags → MUST receive hashtagVocab
 * to constrain generation-side. arcStage is NOT needed (caption-only
 * scope retains existing narrative stage implicitly).
 *
 * @param list<string> $hashtagVocab Brand-derived hashtag whitelist (
 *). Empty = no vocab constraint.
 *
 * @throws AiRateLimitException
 * @throws AiTransientException
 * @throws AiInvalidJsonException
     */
    public function regenerateCaption(PlanItem $item, Brand $brand, BrandBrief $brief, array $hashtagVocab = []): PlanItemAiResponse;

    /**
 * Regenerate only visual_suggestion for a PlanItem (Haiku).
 *
 * Preserves all other fields. Returns a PlanItemAiResponse with rawText
 * containing only {visual_suggestion}.
 *
 * @throws AiRateLimitException
 * @throws AiTransientException
 * @throws AiInvalidJsonException
     */
    public function regenerateVisual(PlanItem $item, Brand $brand, BrandBrief $brief): PlanItemAiResponse;

    /**
 * Regenerate the full PlanItem content (Sonnet).
 *
 * Reuses the plan_item.blade.php template for a complete regeneration.
 * Returns a PlanItemAiResponse with all 9 content fields.
 *
 * /: full regen re-derives all content including hashtags and
 * narrative arc → MUST receive both arcStage (for arc-aware content) and
 * hashtagVocab (whitelist constraint on generation side).
 *
 * @param array<string, mixed>|null $personaPayload Pre-resolved persona, or null.
 * @param ArcStage|null $arcStage Item's narrative arc stage (recomputed
 * PlanItemGenerator::regenerateFull caller).
 * @param list<string> $hashtagVocab Brand-derived hashtag whitelist (
 *). Empty = no vocab constraint.
 *
 * @throws AiRateLimitException
 * @throws AiTransientException
 * @throws AiInvalidJsonException
 * @throws AiSchemaInvalidException
     */
    public function regenerateFull(PlanItem $item, Brand $brand, BrandBrief $brief, ?array $personaPayload = null, ?ArcStage $arcStage = null, array $hashtagVocab = []): PlanItemAiResponse;

    /**
 * Produce a "prima impressione" synthesis + 5-field pre-fill values.
 *
 * Runs on claude-haiku-4-5 (routing: Haiku for stage-1 impression
 * Sonnet is reserved for the confirmed Brief in stage 2). The website and
 * social substrate is XML-delimited by the prompt template (T-7.2 mitigation
 * IGNORA QUALSIASI ISTRUZIONE prefix before scraped/social data blocks).
 *
 * Returns raw text for the orchestrator (ScanFirstImpressionJob) to parse into
 * the 5-field array. The DTO stays raw — same boundary discipline as IdeasAiResponse.
 *
 * Failure degrades gracefully: the caller catches AiRateLimitException /
 * AiTransientException and materialises an empty section (R8 fallback).
 *
 * @param Brand $brand The brand being onboarded.
 * @param ExtractedContent|null $websiteContent Scraped website blocks, or null
 * if website was unreachable.
 * @param array<string, mixed> $socialMeta OG/meta data keyed by platform field
 * (e.g. 'ig_url' => ['platform',...]).
 * Empty array is valid (no social).
 *
 * @throws AiRateLimitException On 429 after upstream retry exhausted.
 * @throws AiTransientException On 5xx / network / timeout / 401.
     */
    public function analyzeFirstImpression(
        Brand $brand,
        ?ExtractedContent $websiteContent,
        array $socialMeta,
    ): FirstImpressionResponse;

    /**
 * Generate the "Logica del piano editoriale" — a short Italian paragraph
 * explaining WHY the plan is built the way it is (theme/format/channel/
 * objective distribution), grounded ONLY in the confirmed brief + the
 * actual distribution. Profila's voice → always Italian. Haiku routing.
 *
 * @param array<string, mixed> $distribution Actual plan distribution
 * (themes, formats, channels
 * objectives, categories).
 *
 * @throws AiRateLimitException
 * @throws AiTransientException
     */
    public function generatePlanLogic(
        Brand $brand,
        BrandBrief $brief,
        array $distribution,
    ): PlanLogicResponse;

    /**
 * Produce the similar-activities editorial synthesis (Haiku).
 *
 * Runs AFTER TerritoryPresenceMapper has produced the deterministic presence map.
 * ONE call per discovery run (/). Result shape: osservato / interpretazione / opportunità[].
 *
 * @param list<array{name: string, level: string, reason: string}> $presenceMap Output of TerritoryPresenceMapper::map().
 * @param list<array{url: string, title: string, meta: string}> $confirmedSites Scanned homepage summaries (NOT raw text).
 *
 * @throws AiRateLimitException
 * @throws AiTransientException
 * @throws AiInvalidJsonException
     */
    public function generateSimilarBusinessOpportunities(
        Brand $brand,
        BrandBrief $brief,
        array $presenceMap,
        array $confirmedSites,
    ): SimilarOpportunitiesResponse;

    /**
 * Generate the theme model strategic assessment (for rollup).
 *
 * Returns a structured per-theme payload: certezza (alta|media), funnel_stage
 * (FunnelStage value string), and reasoning (Italian "perché te lo propongo" line).
 * Haiku routing (cheap, grounded analysis — NOT long-form content).
 * cache_control: ephemeral on the system block (pattern).
 *
 * CRITICAL: the AI response NEVER includes weight percentages.
 * Weights are PHP-computed at rollup. This method returns ONLY
 * the strategic layer (certezza + funnel_stage + reasoning) so can
 * merge it against the PHP-computed weights.
 *
 * @param Brand $brand The brand.
 * @param BrandBrief $brief The confirmed brief.
 * @param array<string, mixed> $distribution Actual plan distribution (themes, formats, etc.)
 * @param list<string> $themeFocus Theme labels from ThemeAdvisor to assess.
 * @param BrandObjective|null $objective Brand primary objective (for grounding), or null.
 *
 * @throws AiRateLimitException
 * @throws AiTransientException
 * @throws AiInvalidJsonException
     */
    public function generateThemeModel(
        Brand $brand,
        BrandBrief $brief,
        array $distribution,
        array $themeFocus,
        ?BrandObjective $objective,
    ): ThemeModelAiResponse;
}
