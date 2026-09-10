<?php

namespace App\Models;

use App\Enums\BrandCategory;
use App\Enums\BrandLanguage;
use App\Enums\BrandObjective;
use App\Enums\BrandStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * Brand — user-owned editorial subject.
 *
 * Layer 1 of the 3-layer multi-tenant isolation pattern
 * Global Scope `owner` filters `WHERE user_id = auth()->id()` when a user
 * is logged in. Layer 2 (BrandPolicy) ships in; Layer 3
 * (relation-only access in Livewire) ships in.
 *
 * `user_id` is intentionally absent from `$fillable` to block mass assignment
 * . The owning user is set via the relation
 * `auth()->user()->brands()->create([...])`
 * or via `Brand::factory()->for($user)->create()` in tests.
 *
 * Slug is auto-generated from `name` (via spatie/laravel-sluggable), immutable
 * on update, and unique per-user (composite DB constraint + extraScope filter).
 *
 * Route key is `slug` — URLs never expose the auto-increment id
 */
class Brand extends Model
{
    use HasFactory, HasSlug, SoftDeletes;

    /**
 * Mass-assignable fields. NOTE: `user_id` and `slug` are NOT included.
 *
 * `language_locked` is included so BrandForm::update can persist the
 * explicit-user-choice signal (/).
 *
 * @var list<string>
     */
    protected $fillable = [
        'name',
        'category',
        'city',
        'website',
        'ig_url',
        'fb_url',
        'gbp_url',
        'description',
        'language',
        'status',
        'language_locked',
        'objective',
        'social_bios',
    ];

    /**
 * Attribute casts.
 *
 * Uses the Laravel 11 `casts()` method (preferred over the `$casts`
 * property).
 *
 * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category'      => BrandCategory::class,
            'status'        => BrandStatus::class,
            'language'      => BrandLanguage::class,
            'last_ai_run_at' => 'datetime',
            'language_locked' => 'boolean',
            'objective'     => BrandObjective::class,
            'social_bios'   => 'array',
        ];
    }

    /**
 * Detected social channels (#6 — "canali rilevati").
 *
 * The only ground-truth signal of which platforms the brand actually uses
 * is the per-channel URL captured at scan time (ig/fb — no URL column
 * exists for LinkedIn/Threads/X, so those can never be "detected"). Returns
 * the {@see \App\Enums\PlanChannel} values for channels with a non-empty URL.
 *
 * GBP (Google Business) is NOT a content channel here (2026-06-06 product
 * review — "i post Google Business non hanno senso"). The `gbp_url` brand
 * field is still stored, but it never drives content generation: Profila does
 * not produce GBP posts. GBP therefore never appears in detected channels
 * wizard pre-selection, the ideas channel constraint, or the PDF.
 *
 * Fallback: a brand with no social URL at all (manual mode) defaults to
 * Instagram + Facebook — the two near-universal channels for local SMBs
 * so content generation always has a sane, non-empty constraint and never
 * silently falls back to "all six" (the #6 bug).
 *
 * @return array<int, string> PlanChannel values, e.g. ['instagram', 'facebook'].
     */
    public function detectedChannels(): array
    {
        $byUrl = [
            'instagram' => $this->ig_url,
            'facebook'  => $this->fb_url,
        ];

        $detected = array_keys(array_filter($byUrl, fn ($url) => filled($url)));

        return $detected !== [] ? array_values($detected) : ['instagram', 'facebook'];
    }

    /**
 * Boot the model — register Layer 1 Global Scope `owner`.
 *
 * The scope filters `user_id = auth()->id()` only when a user is logged
 * in; in tests (factory creation without `actingAs`) the scope is a
 * no-op so cross-tenant setup works.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('owner', function (Builder $query) {
            if (auth()->check()) {
                $query->where('user_id', auth()->id());
            }
        });
    }

    /**
 * Slug options — auto from `name`, immutable, per-user-unique.
 *
 * Without `extraScope`, slugs would be globally
 * unique and two users with the same brand name would conflict.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug')
            ->slugsShouldBeNoLongerThan(50)
            ->doNotGenerateSlugsOnUpdate()
            ->extraScope(fn ($q) => $q->where('user_id', auth()->id()));
    }

    /**
 * Use `slug` (not `id`) as the route key for /brands/{slug} URLs.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
 * Owning user.
 *
 * @return BelongsTo<User, Brand>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
 * Knowledge-base sources owned by this brand.
 *
 * BrandSource has no Global Scope of its own — Layer 1 isolation is
 * inherited transitively through this relation (. Always look up sources via `$brand->sources()`, never
 * `BrandSource::find()` from Livewire components.
 *
 * @return HasMany<BrandSource>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(BrandSource::class);
    }

    /**
 * AI-generated briefs versioned per brand.
 *
 * BrandBrief has no Global Scope of its own — Layer 1 isolation is
 * inherited transitively through this relation.
 * Always look up briefs via `$brand->briefs()`, never
 * `BrandBrief::find()` from Livewire components.
 *
 * @return HasMany<BrandBrief>
     */
    public function briefs(): HasMany
    {
        return $this->hasMany(BrandBrief::class);
    }

    /**
 * Content ideas generated for this brand.
 *
 * ContentIdea has no Global Scope of its own — Layer 1 isolation is
 * inherited transitively through this relation.
 * Always look up ideas via `$brand->ideas()`, never
 * `ContentIdea::find()` from Livewire components.
 *
 * @return HasMany<ContentIdea>
     */
    public function ideas(): HasMany
    {
        return $this->hasMany(ContentIdea::class);
    }

    /**
 * Durable anti-repetition memory — every idea_text ever generated for this
 * brand, surviving board clears (2026-06-04). Feeds the IdeaGenerator
 * avoid-list. Layer 1 transitive (no Global Scope on GeneratedIdeaText).
 *
 * @return HasMany<GeneratedIdeaText>
     */
    public function generatedIdeaTexts(): HasMany
    {
        return $this->hasMany(GeneratedIdeaText::class);
    }

    /**
 * Editorial plans generated for this brand.
 *
 * EditorialPlan has no Global Scope of its own — Layer 1 isolation is
 * inherited transitively through this relation.
 * Always look up plans via `$brand->plans()`, never
 * `EditorialPlan::find()` from Livewire components.
 *
 * @return HasMany<EditorialPlan>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(EditorialPlan::class);
    }

    /**
 * AI-generated strategic analysis for this brand.
 *
 * BrandAnalysis has no Global Scope of its own — Layer 1 isolation is
 * inherited transitively through this relation (Pattern 2).
 * Always look up analysis via `$brand->analysis()`, never
 * `BrandAnalysis::find()` from Livewire components.
 *
 * HasOne (not HasMany) — unique(brand_id) enforces one row per brand.
 *
 * @return HasOne<BrandAnalysis>
     */
    public function analysis(): HasOne
    {
        return $this->hasOne(BrandAnalysis::class);
    }

    /**
 * Returns the latest confirmed BrandBrief for this brand, or null if none.
 *
 * Used by IdeaGenerator and EditorialPlanPolicy::create as the
 * pre-condition check — Idea Board is only accessible when a brief has
 * been confirmed.
 *
 * @return BrandBrief|null
     */
    public function latestConfirmedBrief(): ?BrandBrief
    {
        return $this->briefs()
            ->whereNotNull('confirmed_at')
            ->latest('version')
            ->first();
    }
}
