<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\BriefStatus;
use App\Models\Brand;
use App\Models\BrandBrief;
use App\Models\User;

/**
 * BrandBriefPolicy — Layer 2 of the 3-layer multi-tenant isolation pattern
 * applied to BrandBrief.
 *
 * Layer 1 is INHERITED transitively via the parent Brand's Global Scope.
 * BrandBrief has no own Global Scope. This Policy is defense-in-depth
 * `$brief->brand->user_id === $user->id`.
 *
 * `create` and `regenerate` take a `(User, Brand)` signature (Gate accepts
 * arrays for the model arg: `$user->can('create', [BrandBrief::class, $brand])`).
 *
 * `confirm` is a custom ability — only callable on DRAFT briefs (
 * only-confirm-once invariant). Re-confirming an already-confirmed brief is
 * denied at the Policy boundary, not relied on UI absence.
 *
 * NO `update` method: editing fields + clicking "Conferma" is a single
 * Livewire action `confirm`, not a separate PATCH endpoint (
 * anti-promise — no REST API).
 *
 * NO `delete` method: briefs are never deleted — version trail is the audit
 * (KB).
 *
 * Auto-discovered by Laravel 11 (App\Models\BrandBrief → App\Policies\
 * BrandBriefPolicy); no explicit Gate::policy() registration needed.
 */
class BrandBriefPolicy
{
    /**
 * List/index briefs for a given brand — only the brand owner.
 * Gate signature: `$user->can('viewAny', [BrandBrief::class, $brand])`.
     */
    public function viewAny(User $user, Brand $brand): bool
    {
        return $brand->user_id === $user->id;
    }

    /**
 * View a specific brief — only the owner of the parent brand
 * (transitive ownership via `$brief->brand->user_id`).
     */
    public function view(User $user, BrandBrief $brief): bool
    {
        return $brief->brand->user_id === $user->id;
    }

    /**
 * Create a new brief on the given Brand — only the brand owner.
 * (Free-tier cap on briefs is NOT in MVP — versioning allows
 * unlimited regens; rate limiting happens at the AI call boundary.)
     */
    public function create(User $user, Brand $brand): bool
    {
        return $brand->user_id === $user->id;
    }

    /**
 * Regenerate a brief (produce a new version) — only the brand owner.
 * Same authority as `create`; the distinction is semantic (UI shows
 * "Rigenera brief" instead of "Genera Brand Brief" when ≥1 brief exists).
     */
    public function regenerate(User $user, Brand $brand): bool
    {
        return $brand->user_id === $user->id;
    }

    /**
 * Confirm the brief — only the owner of the parent brand AND only when
 * the brief is still in Draft status. invariant: an already-confirmed
 * brief cannot be re-confirmed (would mutate a finalized record; the user
 * must Rigenera and confirm the new draft instead).
     */
    public function confirm(User $user, BrandBrief $brief): bool
    {
        return $brief->brand->user_id === $user->id
            && $brief->status === BriefStatus::Draft;
    }
}
