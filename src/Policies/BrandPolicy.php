<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;

/**
 * BrandPolicy — Layer 2 of the 3-layer multi-tenant isolation pattern.
 *
 * Layer 1 (Global Scope `owner` on Brand) already hides cross-user brands
 * from queries when a user is logged in. This Policy is defense-in-depth
 * even if a query bypasses the scope (Brand::withoutGlobalScopes()), the
 * explicit ownership check here blocks the action.
 *
 * The `create` action delegates to {@see User::canCreateBrand()} — single
 * source of truth for the free-tier 1-brand limit. DO NOT duplicate that
 * rule here; change it in one place (the User model) and it propagates.
 *
 * Auto-discovered by Laravel 11 via convention (App\Models\Brand →
 * App\Policies\BrandPolicy); no explicit Gate::policy call needed.
 */
class BrandPolicy
{
    /**
 * Anyone authenticated may "view any" — the list is filtered by the
 * Layer 1 Global Scope, not by this gate.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
 * View a specific brand — only the owner.
     */
    public function view(User $user, Brand $brand): bool
    {
        return $brand->user_id === $user->id;
    }

    /**
 * Create a new brand — delegates to the User model's free-tier check.
 *
 * Pro users: always true (no quota).
 * Free users: true iff they own fewer than 1 brand.
     */
    public function create(User $user): bool
    {
        return $user->canCreateBrand();
    }

    /**
 * Update a brand — only the owner.
     */
    public function update(User $user, Brand $brand): bool
    {
        return $brand->user_id === $user->id;
    }

    /**
 * Soft-delete (archive) a brand — only the owner.
     */
    public function delete(User $user, Brand $brand): bool
    {
        return $brand->user_id === $user->id;
    }

    /**
 * Restore a soft-deleted brand — only the owner.
     */
    public function restore(User $user, Brand $brand): bool
    {
        return $brand->user_id === $user->id;
    }

    /**
 * Force-delete (hard remove) a brand — only the owner.
 *
 * does not surface a forceDelete action; this method is
 * included for completeness so the Policy is exhaustive for any
 * future hard-delete flow.
     */
    public function forceDelete(User $user, Brand $brand): bool
    {
        return $brand->user_id === $user->id;
    }
}
