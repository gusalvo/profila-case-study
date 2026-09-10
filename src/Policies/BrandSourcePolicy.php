<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Brand;
use App\Models\BrandSource;
use App\Models\User;

/**
 * BrandSourcePolicy — Layer 2 of the 3-layer multi-tenant isolation pattern
 * applied to BrandSource.
 *
 * Layer 1 is INHERITED transitively via the parent Brand's Global Scope.
 * BrandSource has no own Global Scope. This Policy is defense-in-depth
 * even if a query reaches a source whose brand belongs to another user, the
 * explicit `$source->brand->user_id === $user->id` check blocks the action.
 *
 * `create` takes a `(User, Brand)` signature and delegates to
 * {@see User::canCreateSource()} — single source of truth for the free-tier
 * 50-cap. Laravel's Gate accepts arrays for the model arg
 * `$user->can('create', [BrandSource::class, $brand])`.
 *
 * Auto-discovered by Laravel 11 via convention (App\Models\BrandSource →
 * App\Policies\BrandSourcePolicy); no explicit Gate::policy call needed.
 */
class BrandSourcePolicy
{
    /**
 * Anyone authenticated may "view any" — the list is filtered through the
 * parent Brand's Layer 1 Global Scope (transitively via $brand->sources()).
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
 * View a specific source — only the owner of the parent brand.
     */
    public function view(User $user, BrandSource $source): bool
    {
        return $source->brand->user_id === $user->id;
    }

    /**
 * Create a new source on the given Brand. Two requirements
 * 1. The user must own the brand (cross-tenant guard).
 * 2. The user must be under the free-tier cap (or Pro).
 *
 * Delegates the cap logic to User::canCreateSource — single source
 * truth. Do NOT inline the count check here.
     */
    public function create(User $user, Brand $brand): bool
    {
        return $brand->user_id === $user->id && $user->canCreateSource($brand);
    }

    /**
 * Update a source — only the owner of the parent brand.
     */
    public function update(User $user, BrandSource $source): bool
    {
        return $source->brand->user_id === $user->id;
    }

    /**
 * Soft-delete (archive) a source — only the owner of the parent brand.
     */
    public function delete(User $user, BrandSource $source): bool
    {
        return $source->brand->user_id === $user->id;
    }

    /**
 * Restore a soft-deleted source — only the owner of the parent brand.
 *
 * MVP does not surface a restore UI, but the method is
 * included for completeness so the Policy is exhaustive for any future
 * restore flow.
     */
    public function restore(User $user, BrandSource $source): bool
    {
        return $source->brand->user_id === $user->id;
    }

    /**
 * Force-delete (hard remove) a source — only the owner of the parent brand.
 *
 * MVP does not surface a forceDelete action; this method is
 * included for completeness.
     */
    public function forceDelete(User $user, BrandSource $source): bool
    {
        return $source->brand->user_id === $user->id;
    }
}
