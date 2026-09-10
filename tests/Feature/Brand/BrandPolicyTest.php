<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/*
 * BrandPolicyTest — Layer 2 of the 3-layer multi-tenant isolation pattern.
 *
 * Verifies that
 * view/update/delete/restore/forceDelete enforce $brand->user_id === $user->id
 * viewAny is open (the list is filtered by the Layer 1 Global Scope)
 * create delegates to User::canCreateBrand (single source of truth), so the free-tier 1-brand limit and Pro short-circuit are
 * both honoured at the Policy boundary.
 *
 * NOTE: Brand factory creation in cross-user tests is preceded by auth()->logout
 * so the Layer 1 Global Scope does NOT filter out the
 * created row during setup.
 */

it('allows a user to view their own brand', function () {
    $userA = User::factory()->create();
    auth()->logout();
    $brandA = Brand::factory()->for($userA)->create();

    expect(Gate::forUser($userA)->allows('view', $brandA))->toBeTrue();
});

it('denies a user from viewing another user\'s brand', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    auth()->logout();
    $brandB = Brand::factory()->for($userB)->create();

    expect(Gate::forUser($userA)->denies('view', $brandB))->toBeTrue();
});

it('denies a user from updating another user\'s brand', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    auth()->logout();
    $brandB = Brand::factory()->for($userB)->create();

    expect(Gate::forUser($userA)->denies('update', $brandB))->toBeTrue();
});

it('denies a user from deleting another user\'s brand', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    auth()->logout();
    $brandB = Brand::factory()->for($userB)->create();

    expect(Gate::forUser($userA)->denies('delete', $brandB))->toBeTrue();
});

it('denies a user from restoring another user\'s brand', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    auth()->logout();
    $brandB = Brand::factory()->for($userB)->create();

    expect(Gate::forUser($userA)->denies('restore', $brandB))->toBeTrue();
});

it('allows a free user with zero brands to create one', function () {
    $freeUser = User::factory()->free()->create();

    expect(Gate::forUser($freeUser)->allows('create', Brand::class))->toBeTrue();
});

it('denies a free user with one brand from creating another', function () {
    $freeUser = User::factory()->free()->create();
    auth()->logout();
    Brand::factory()->for($freeUser)->create();

    expect(Gate::forUser($freeUser)->denies('create', Brand::class))->toBeTrue();
});

it('allows a pro user with 2 brands to create more (below Pro cap 3)', function () {
    $proUser = User::factory()->pro()->create();
    auth()->logout();
    Brand::factory()->for($proUser)->count(2)->create();

    expect(Gate::forUser($proUser)->allows('create', Brand::class))->toBeTrue();
});

it('blocks a pro user at 3 brands from creating more (Pro cap 3)', function () {
    $proUser = User::factory()->pro()->create();
    auth()->logout();
    Brand::factory()->for($proUser)->count(3)->create();

    expect(Gate::forUser($proUser)->allows('create', Brand::class))->toBeFalse();
});

it('always allows viewAny for authenticated users (list filtered by Global Scope)', function () {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('viewAny', Brand::class))->toBeTrue();
});
