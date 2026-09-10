<?php

declare(strict_types=1);

use App\Livewire\Brands\Archived;
use App\Livewire\Brands\Index;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Cross-tenant isolation integration tests (CRITICAL)
|--------------------------------------------------------------------------
|
| Success Criterion 5 of: "A second user cannot view, edit
| or access the first user's brand via URL, list, or relation; the 3-layer
| multi-tenant isolation pattern is in place with at least one integration
| test covering brand cross-tenant access."
|
| This is the CRITICAL test. If green, the 3-layer pattern (Global
| Scope + Policy + Relation) is empirically dogfooded end-to-end
| Layer 1 (Global Scope `owner` on Brand) hides foreign rows from queries.
| Layer 2 (BrandPolicy) is defense-in-depth: even bypassing Layer 1, the
| ownership check denies.
| Layer 3 (auth()->user()->brands()->.. access in Livewire components)
| ensures every controller boundary uses the user-scoped relation.
|
| Assertion convention
| Cross-tenant view/update → 404 (assertNotFound), NOT 403 (assertForbidden).
| The Global Scope hides existence, not just access. A foreign brand looks
| like a non-existent brand — no information leak.
|
| We use HTTP-level requests ($this->get(route(..))) for cross-tenant view
| so Laravel's exception handler converts ModelNotFoundException → 404
| response (Livewire::test propagates the exception instead of converting it).
|
| Setup convention
| ALWAYS call auth()->logout before Brand::factory()->for($otherUser)->create.
| The Global Scope filters by auth()->id at factory time too; if you're
| currently acting as user A and try to create a brand for user B without
| logging out, the row is still created but Layer 1 will make subsequent
| queries inconsistent during setup. Logout → factory → re-login is safe.
*/

it('returns 404 when user A tries to view user B brand via show route', function () {
    $userA = User::factory()->create(['email_verified_at' => now()]);
    $userB = User::factory()->create(['email_verified_at' => now()]);
    auth()->logout();
    $brandB = Brand::factory()->for($userB)->create(['name' => 'Hotel Mare di B']);

 // HTTP-level GET so Laravel's exception handler maps
 // ModelNotFoundException (from Show.mount's firstOrFail) to 404.
    $this->actingAs($userA)
        ->get(route('brands.show', ['slug' => $brandB->slug]))
        ->assertNotFound();
});

it('user A index only shows their own brands (assertSee + assertDontSee)', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    auth()->logout();

    Brand::factory()->for($userA)->create(['name' => 'Hotel Brand A']);
    Brand::factory()->for($userB)->create(['name' => 'Hotel Brand B']);

 // Render user A's Index component. The Global Scope filters $brands to
 // user A's brands only; user B's brand name is absent from the rendered HTML.
    Livewire::actingAs($userA)
        ->test(Index::class)
        ->assertOk()
        ->assertSee('Hotel Brand A')
        ->assertDontSee('Hotel Brand B');
});

it('two users can have a brand with the same name (slug per-user unique)', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    auth()->logout();

    $brandA = Brand::factory()->for($userA)->create(['name' => 'Caffè Italia']);
    $brandB = Brand::factory()->for($userB)->create(['name' => 'Caffè Italia']);

 // Both slugs equal 'caffe-italia' but ownership differs — composite
 // DB unique(user_id, slug) + HasSlug extraScope make this lawful.
    expect($brandA->slug)->toBe('caffe-italia')
        ->and($brandB->slug)->toBe('caffe-italia')
        ->and($brandA->user_id)->not->toBe($brandB->user_id)
 // Both rows exist when global scope is bypassed.
        ->and(Brand::withoutGlobalScopes()->where('slug', 'caffe-italia')->count())->toBe(2);
});

it('user A cannot archive user B brand via URL or Livewire (404 at mount)', function () {
    $userA = User::factory()->create(['email_verified_at' => now()]);
    $userB = User::factory()->create(['email_verified_at' => now()]);
    auth()->logout();
    $brandB = Brand::factory()->for($userB)->create();

 // HTTP-level GET on the brand show URL — the archive action is only
 // reachable from inside the Show component; if Show.mount fails (404)
 // archive can never run. We verify the gating at the route boundary.
    $this->actingAs($userA)
        ->get(route('brands.show', ['slug' => $brandB->slug]))
        ->assertNotFound();

 // Brand B is still present (not soft-deleted) for user B.
    $this->actingAs($userB);
    expect(Brand::where('id', $brandB->id)->exists())->toBeTrue();
});

it('user A cannot restore user B archived brand', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    auth()->logout();
    $brandB = Brand::factory()->for($userB)->create();
    $brandB->delete(); // soft-delete

 // Archived.restore($slug) uses auth()->user()->brands()->onlyTrashed
 // >where('slug',...)->firstOrFail. user A's relation has no trashed
 // brand matching this slug → ModelNotFoundException.
 //
 // Livewire::test propagates the exception to the test (instead of going
 // through the HTTP exception handler which would convert to 404). At HTTP
 // level the exception is mapped to 404 by Laravel; the integration here
 // asserts the underlying behavior — no foreign brand can be restored.
    expect(fn () => Livewire::actingAs($userA)
        ->test(Archived::class)
        ->call('restore', $brandB->slug)
    )->toThrow(ModelNotFoundException::class);

 // Layer 1 still hides the brand from user A's relation post-attempt.
    $this->actingAs($userA);
    expect(Brand::withTrashed()->where('id', $brandB->id)->exists())->toBeFalse();

 // Brand B is still trashed under user B's ownership (unchanged).
    $this->actingAs($userB);
    expect(Brand::withTrashed()->where('id', $brandB->id)->whereNotNull('deleted_at')->exists())
        ->toBeTrue();
});

it('Brand factory pattern is auth()->logout() before factory-for-other-user (regression)', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

 // regression guard
 // While user A is acting, factory-creating a Brand FOR user B should
 // still work because Brand::booted only filters READS when
 // auth()->check is true — INSERTS go through the relation `->for($userB)`
 // which writes user_id=$userB->id. The Global Scope DOES NOT mutate
 // INSERTs; it only filters SELECTs.
 //
 // However, querying RIGHT AFTER while still acting as user A will hide
 // the row from default queries — which is the whole point of Layer 1.
 // The correct pattern for cross-tenant fixtures is auth()->logout
 // BEFORE the factory, then re-actingAs in the assertion phase.
    $this->actingAs($userA);
    auth()->logout(); // <-- mandatory regression guard
    $brandB = Brand::factory()->for($userB)->create();

 // Row exists in the database (withoutGlobalScopes proves it).
    expect(Brand::withoutGlobalScopes()->where('id', $brandB->id)->exists())->toBeTrue()
        ->and($brandB->user_id)->toBe($userB->id);

 // From user A's perspective with Layer 1 active, the row is invisible.
    $this->actingAs($userA);
    expect(Brand::where('id', $brandB->id)->exists())->toBeFalse();

 // From user B's perspective with Layer 1 active, the row is visible.
    $this->actingAs($userB);
    expect(Brand::where('id', $brandB->id)->exists())->toBeTrue();
});
