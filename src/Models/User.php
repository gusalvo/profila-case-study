<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanLifecycleStatus;
use App\Enums\PlanTier;
use App\Enums\SourceType;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
 /** @use HasFactory<\Database\Factories\UserFactory>*/
    use HasFactory, Notifiable, SoftDeletes;

    /**
 * The attributes that are mass assignable.
 *
 * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'plan_tier',
 // Onboarding state columns.
 // Added to $fillable to allow internal service update calls.
 // These are NEVER exposed to HTTP mass-assignment (no routes accept them).
        'onboarding_started_at',
        'onboarding_completed_at',
        'onboarding_dismissed_at',
        'first_brand_id',
        'last_export_at',
 //per-user PDF footer opt-in toggle. In $fillable for profile/admin updates.
 // NEVER exposed to HTTP mass-assignment via brand/plan routes.
        'pdf_footer_removed',
    ];

    /**
 * The attributes that should be hidden for serialization.
 *
 * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
 * Get the attributes that should be cast.
 *
 * Onboarding timestamp columns explicitly cast to 'datetime' (Carbon) even
 * though Laravel 11 auto-casts *_at columns — explicit declarations are safer against
 * future framework changes and make intent clear in code review.
 *
 * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at'       => 'datetime',
            'onboarding_started_at'   => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'onboarding_dismissed_at' => 'datetime',
            'last_export_at'          => 'datetime',
            'password'                => 'hashed',
            'plan_tier'               => PlanTier::class,
 //is_admin cast to boolean. NOT in $fillable (mass-assignment guard).
            'is_admin'                => 'boolean',
 //per-user PDF footer opt-in toggle. Cast to boolean; in $fillable
 // so profile/admin toggle update calls can set it. Default false (footer shown).
            'pdf_footer_removed'      => 'boolean',
        ];
    }

    /**
 * Admin identification helper.
 *
 * Returns true iff this user is a system administrator.
 * Convenience wrapper over the `is_admin` boolean column.
 * NOT in $fillable — only settable via query-builder update (seeder)
 * or forceFill in admin actions (never HTTP mass-assignment).
     */
    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /**
 * /: Cascade soft-delete and restore to owned brands.
 *
 * Registered in booted so admin-triggered user delete/restore
 * propagates to all of the user's brands.
 *
 * CRITICAL: MUST use withoutGlobalScope('owner') (singular, named)
 * never withoutGlobalScopes plural (strips SoftDeletingScope) and
 * never $user->brands (filtered by auth()->id = admin, not target user).
 *
 * documents this gated bypass.
     */
    protected static function booted(): void
    {
 // Cascade soft-delete to all of this user's active brands.
        static::deleting(function (User $user): void {
            if ($user->isForceDeleting()) {
                return;
            }

            \App\Models\Brand::withoutGlobalScope('owner')
                ->where('user_id', $user->id)
                ->whereNull('deleted_at')
                ->each(fn ($brand) => $brand->delete());
        });

 // Cascade restore to brands deleted in the same cascade window.
 //brands deleted BEFORE the user cascade have a different deleted_at
 // timestamp — they must NOT be restored (pre-existing deletions stay trashed).
 // Match a bounded ±1s window rather than exact deleted_at equality
 // MySQL truncates fractional seconds while Carbon keeps microseconds, so the
 // brand and user deleted_at can differ by sub-second amounts in the same cascade.
 // A pre-existing deletion (seconds+ earlier) still falls outside the window.
        static::restoring(function (User $user): void {
            if ($user->deleted_at === null) {
                return;
            }

            \App\Models\Brand::withoutGlobalScope('owner')
                ->onlyTrashed()
                ->where('user_id', $user->id)
                ->whereBetween('deleted_at', [
                    $user->deleted_at->copy()->subSecond(),
                    $user->deleted_at->copy()->addSecond(),
                ])
                ->each(fn ($brand) => $brand->restore());
        });
    }

    /**
 * Brands owned by this user.
 *
 * Stub relation — the App\Models\Brand model is created in.
 * PHP resolves the class reference lazily, so declaring it here ahead
 * time is safe and unlocks the canCreateBrand free-tier guard.
 *
 * @return HasMany<\App\Models\Brand>
     */
    public function brands(): HasMany
    {
        return $this->hasMany(\App\Models\Brand::class);
    }

    /**
 * The user's first (onboarding) brand.
 *
 * BelongsTo via `first_brand_id` (added by migration).
 * Passes through Brand's Global Scope `owner`: if
 * `first_brand_id` points to another user's Brand, the scope hides it
 * and returns null — mitigating the cross-tenant FK leak.
 *
 * Use `$user->firstBrand` (accessor) or `$user->firstBrand()` (relation)
 * to retrieve; NEVER bypass via `Brand::find($user->first_brand_id)`.
 *
 * @return BelongsTo<\App\Models\Brand, self>
     */
    public function firstBrand(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Brand::class, 'first_brand_id');
    }

    /**
 * Single source of truth for the brand-count cap per tier (/).
 *
 * Pro tier: fewer than 3 brands (finite cap — replaces the old "always true").
 * Free tier: fewer than 1 brand (unchanged).
 *
 * NEVER duplicate this logic. BrandPolicy::create and the
 * Livewire UI must call this method — +.
 * Policies/Livewire/Blade MUST delegate.
     */
    public function canCreateBrand(): bool
    {
 // Defensive: until creates the Brand model + brands table
 // counting via the stub relation would fatal with "Class Brand not found".
 // A user clearly cannot own a brand whose class doesn't yet exist, so
 // the free-tier limit (<1) is satisfied (guard becomes no-op once Brand lands).
        if (! class_exists(\App\Models\Brand::class)) {
            return true;
        }

        $cap = $this->plan_tier === PlanTier::Pro ? 3 : 1;

        return $this->brands()->count() < $cap;
    }

    /**
 * Single source of truth for the free-tier "50 sources per brand" limit.
 *
 * Pro tier: always true (short-circuit).
 * Free tier: true iff the given brand has fewer than 50 active sources.
 *
 * The `$brand->sources()` relation goes through the SoftDeletes default
 * scope which hides `deleted_at IS NOT NULL`, so archived (soft-deleted)
 * sources do NOT count toward the cap (parity with canCreateBrand
 * SUMMARY handoff invariant).
 *
 * NEVER duplicate this logic. BrandSourcePolicy::create
 * and the Livewire UI MUST delegate to this method
 *
     */
    public function canCreateSource(\App\Models\Brand $brand): bool
    {
        if ($this->plan_tier === PlanTier::Pro) {
            return true;
        }

        return $brand->sources()->count() < 50;
    }

    /**
 * Single source of truth for the plans-per-calendar-month cap per tier (/).
 *
 * Pro tier: fewer than 4 active plans this calendar month (finite cap — replaces old "always true").
 * Free tier: true iff the brand has NO active (draft or final) plan this month (cap 1, unchanged).
 *
 * Filter: `whereIn('lifecycle_status', ['draft', 'final'])` — archived plans
 * ("Rigenera piano") do NOT count toward the monthly limit. Note
 * per lock, when "Rigenera piano" fires, the old plan is archived AFTER
 * the new plan creation attempt, so at check-time the old plan is still
 * draft/final → correctly blocks Free tier from creating a 2nd active plan.
 *
 * Month boundaries use now('Europe/Rome') (never Carbon::now()).
 *
 * NEVER duplicate this logic. EditorialPlanPolicy::create
 * MUST delegate to this method — single source of truth.
 * Policies/Livewire/Blade MUST delegate.
 *
 * @param \App\Models\Brand $brand
     */
    public function canCreatePlanThisMonth(\App\Models\Brand $brand): bool
    {
        $count = $brand->plans()
            ->whereIn('lifecycle_status', [
                PlanLifecycleStatus::Draft->value,
                PlanLifecycleStatus::Final->value,
            ])
            ->whereBetween('created_at', [
                now('Europe/Rome')->startOfMonth(),
                now('Europe/Rome')->endOfMonth(),
            ])
            ->count();

        $cap = $this->plan_tier === PlanTier::Pro ? 4 : 1;

        return $count < $cap;
    }

    /**
 * Single source of truth for the plan-item count cap per tier.
 *
 * Pro tier: cap is 30 items per plan.
 * Free tier: cap is 20 items per plan.
 *
 * Returns true iff the plan currently has fewer items than the tier cap.
 *
 * NEVER duplicate this logic. PlanItemPolicy and the
 * Livewire wizard MUST delegate to this method —.
 *
 * @param \App\Models\EditorialPlan $plan
     */
    public function canAddPlanItem(\App\Models\EditorialPlan $plan): bool
    {
        $cap = $this->plan_tier === PlanTier::Pro ? 30 : 20;

        return $plan->items()->count() < $cap;
    }

 //
 // Tier-capability methods (/)
 //
 // Single source of truth for every downstream gate that branches on plan_tier.
 // NEVER duplicate this logic. Policies / Livewire / Blade MUST delegate to
 // these methods. and 16-05 consume them.
 //

    /**
 * Single source of truth for the continuity-window size per tier (/).
 *
 * Pro tier: 3 recent plans are fed into the ContinuityDigest.
 * Free tier: 1 recent plan.
 *
 * NEVER duplicate this logic. Policies/Livewire/Blade MUST delegate.
     */
    public function continuityWindowSize(): int
    {
        return $this->plan_tier === PlanTier::Pro ? 3 : 1;
    }

    /**
 * Single source of truth for the bio-variant limit per tier.
 *
 * Pro tier: 3 bio variants in the bio chooser.
 * Free tier: 1 bio variant.
 *
 * NEVER duplicate this logic. Policies/Livewire/Blade MUST delegate.
     */
    public function bioVariantLimit(): int
    {
        return $this->plan_tier === PlanTier::Pro ? 3 : 1;
    }

    /**
 * Single source of truth for the bilingual language gate per tier.
 *
 * Pro tier: true — the `it_en` brand language option and bilingual
 * caption pipeline are available.
 * Free tier: false — only `it` or `en` are available.
 *
 * NEVER duplicate this logic. Policies/Livewire/Blade MUST delegate.
     */
    public function canSelectBilingual(): bool
    {
        return $this->plan_tier === PlanTier::Pro;
    }

    /**
 * Single source of truth for the PDF footer-removal gate per tier.
 *
 * Pro tier: true — the "Rimuovi footer PDF" option is available.
 * Free tier: false — the Profila footer is always shown.
 *
 * NEVER duplicate this logic. Policies/Livewire/Blade MUST delegate.
     */
    public function canRemovePdfFooter(): bool
    {
        return $this->plan_tier === PlanTier::Pro;
    }

    /**
 * Single source of truth for the BrandAnalysis depth gate per tier.
 *
 * Pro tier: true — full analysis surface is returned.
 * Free tier: false — base (condensed) analysis surface is returned.
 *
 * NEVER duplicate this logic. Policies/Livewire/Blade MUST delegate.
     */
    public function hasFullAnalysisDepth(): bool
    {
        return $this->plan_tier === PlanTier::Pro;
    }

    /**
 * Single source of truth for the per-plan regeneration budget per tier.
 *
 * Pro tier: 5 regenerations per plan item (broad budget).
 * Free tier: 2 regenerations per plan item (few budget).
 *
 * Both values are > 0; the Pro budget is always greater than the Free budget.
 * These counts are the per-plan regeneration budget (discretionary picks).
 *
 * NEVER duplicate this logic. Policies/Livewire/Blade MUST delegate.
     */
    public function regenerationAllowance(): int
    {
        return $this->plan_tier === PlanTier::Pro ? 5 : 2;
    }

    /**
 * First-time onboarding gate.
 *
 * Returns true iff the user has NOT yet linked a first brand (wizard not
 * completed or dismissed) AND has NOT explicitly dismissed the onboarding
 * flow.
 *
 * Called
 * (a) Breeze RegisteredUserController / LoginResponse post-signup redirect
 * (b) Dashboard checklist dismiss/restore logic
 *
 * NEVER duplicate this logic — single source of truth
 *.3.
     */
    public function needsOnboarding(): bool
    {
        return $this->first_brand_id === null
            && $this->onboarding_dismissed_at === null;
    }

    /**
 * single source of truth — Minimum-KB qualitative rule.
 *
 * Returns true iff the given brand's active sources satisfy BOTH conditions
 * 1. services_count >= 2
 * 2. (tone_rule_count + word_use_count) >= 1
 *
 * /: the "avoid" condition
 * (word_avoid + tone_rule[avoid] >= 1) has been DROPPED. The scrape-first path
 * often yields services + tone but no avoid sources; blocking at Brief generation
 * on missing avoid prevents valid onboarding flows. words_to_avoid remains a
 * Brief field (non-blocking, pre-filled when derivable, empty otherwise).
 *
 * Called
 * (a) Onboarding\Wizard Step 3 UI gate (enables/disables "Genera Brief" CTA)
 * (b) BriefGenerator::preCheck (server-side enforcement, defense in depth)
 * (c) ChecklistTracker steps 2/3/4 of 8
 *
 * NEVER duplicate this logic — single source of truth
 *.3. The JSON-path syntax `metadata->rule_type` is supported
 * both MySQL 8 (JSON_EXTRACT) and SQLite (json_extract) via Laravel's Eloquent
 * driver — verified via HasBrandSourceMetadataRules trait.
     */
    public function onboardingMinimumKbMet(\App\Models\Brand $brand): bool
    {
        $sources = $brand->sources()->where('is_active', true);

        $serviceCount  = (clone $sources)->where('type', SourceType::Service->value)->count();
        $toneRuleCount = (clone $sources)->where('type', SourceType::ToneRule->value)->count();
        $wordUseCount  = (clone $sources)->where('type', SourceType::WordUse->value)->count();

        return $serviceCount >= 2
            && ($toneRuleCount + $wordUseCount) >= 1;
    }

    /**
 * 8-step checklist progress. Delegates to ChecklistTracker.
 *
 * Returns an array with progress data for the onboarding checklist widget.
 * Defensive guard: if (ChecklistTracker) has not yet shipped
 * returns a placeholder array so dashboard renders before 06-04 lands
 * do not fatal. Once 06-04 ships, the class_exists check short-circuits
 * to the production path.
 *
 * @return array{percent: int, steps: array<int, array{label: string, done: bool}>, completed_count: int, total: int}
     */
    public function onboardingProgress(): array
    {
        if (! class_exists(\App\Services\Onboarding\ChecklistTracker::class)) {
 // TODO: remove guard once (ChecklistTracker) ships.
            return ['percent' => 0, 'steps' => [], 'completed_count' => 0, 'total' => 8];
        }

        return app(\App\Services\Onboarding\ChecklistTracker::class)->for($this);
    }

    /**
 * All editorial plans owned by this user (through their brands).
 *
 * Convenience relation for admin queries / analytics. Livewire components
 * MUST still use `auth()->user()->brands()->..->plans()` relation chain
 * for Layer 3 isolation.
 *
 * @return HasManyThrough<\App\Models\EditorialPlan>
     */
    public function plans(): HasManyThrough
    {
        return $this->hasManyThrough(
            \App\Models\EditorialPlan::class,
            \App\Models\Brand::class
        );
    }
}
