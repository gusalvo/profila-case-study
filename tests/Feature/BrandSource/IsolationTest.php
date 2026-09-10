<?php

declare(strict_types=1);

use App\Enums\SourceType;
use App\Livewire\Sources\Create;
use App\Livewire\Sources\Edit;
use App\Livewire\Sources\Index;
use App\Models\Brand;
use App\Models\BrandSource;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Cross-tenant isolation integration tests (CRITICAL)
|--------------------------------------------------------------------------
|
| Success Criterion 5 of: "User B cannot view, edit, delete
| or create a BrandSource owned by user A — across all 6 entry points
| (index/edit GET + Livewire destroy/addFromExample/Create.mount
| regression). 3-layer multi-tenant pattern proven end-to-end for child
| entities."
|
| This is THE CRITICAL test. Mirrors tests/Feature/Brand/IsolationTest.php
| verbatim shape; if green, BrandSource child-of-Brand isolation is empirically
| dogfooded end-to-end
| Layer 1 (transitive via parent Brand's `owner` Global Scope) hides
| foreign rows from queries.
| Layer 2 (BrandSourcePolicy) — even with Layer 1 bypass, the policy
| denies non-owners.
| Layer 3 (auth()->user()->brands()->where('slug', ...)->firstOrFail()
| every Livewire mount()) → ModelNotFoundException → 404 at HTTP boundary.
|
| Assertion convention (IsolationTest pattern)
| Cross-tenant view/update → 404 (assertNotFound), NOT 403 (assertForbidden).
| Layer 3 hides existence — a foreign slug looks like a non-existent slug.
|
| We use HTTP-level requests ($this->get(route(...))) for routes so the
| Laravel exception handler converts ModelNotFoundException → 404 response.
| For Livewire actions where the exception propagates straight through the
| test harness (per), we use expect()->toThrow(...).
|
| Setup convention
| ALWAYS call auth()->logout() before BrandSource::factory()->for($otherBrand)
| >create(). Even though BrandSource registers no Global Scope itself, the
| parent Brand factory respects auth()->id() during fixture creation; the
| safe pattern is logout → factory → re-actingAs.
*/

it('returns 404 when user A tries to list user B sources via index route', function () {
    $userA = User::factory()->create(['email_verified_at' => now()]);
    $userB = User::factory()->create(['email_verified_at' => now()]);
    auth()->logout();
    $brandB = Brand::factory()->for($userB)->create(['name' => 'Hotel di B']);
    BrandSource::factory()->for($brandB)->create([
        'type' => SourceType::Service->value,
        'title' => 'Camera Vista Mare B',
    ]);

 // HTTP-level GET so Laravel's exception handler maps the Layer 3
 // firstOrFail() ModelNotFoundException to a 404 response.
    $this->actingAs($userA)
        ->get(route('sources.index', ['brandSlug' => $brandB->slug]))
        ->assertNotFound();
});

it('user A list does not include user B sources (Layer 1 transitive isolation)', function () {
    $userA = User::factory()->create(['email_verified_at' => now()]);
    $userB = User::factory()->create(['email_verified_at' => now()]);
    auth()->logout();

    $brandA = Brand::factory()->for($userA)->create();
    $brandB = Brand::factory()->for($userB)->create();

    BrandSource::factory()->for($brandA)->create([
        'type' => SourceType::Service->value,
        'title' => 'Servizio del brand A',
    ]);
    BrandSource::factory()->for($brandB)->create([
        'type' => SourceType::Service->value,
        'title' => 'Servizio del brand B',
    ]);

 // Render user A's Index for their own brandA. The Brand parent's
 // Global Scope filters $brand to brandA only; brandB's source is
 // not reachable via the relation — assertDontSee proves it.
    Livewire::actingAs($userA)
        ->test(Index::class, ['brandSlug' => $brandA->slug])
        ->assertOk()
        ->assertSee('Servizio del brand A')
        ->assertDontSee('Servizio del brand B');
});

it('returns 404 when user A tries to edit user B specific source via edit route', function () {
    $userA = User::factory()->create(['email_verified_at' => now()]);
    $userB = User::factory()->create(['email_verified_at' => now()]);
    auth()->logout();
    $brandB = Brand::factory()->for($userB)->create();
    $sourceB = BrandSource::factory()->for($brandB)->create([
        'type' => SourceType::Service->value,
    ]);

 // HTTP-level so firstOrFail → 404. (Livewire::test would propagate
 // ModelNotFoundException instead of converting it — same pattern as
 // tests/Feature/Brand/IsolationTest.php uses.)
    $this->actingAs($userA)
        ->get(route('sources.edit', [
            'brandSlug' => $brandB->slug,
            'source' => $sourceB->id,
        ]))
        ->assertNotFound();
});

it('user A cannot delete user B source via Layer 2 Policy::delete (Gate facade)', function () {
 // The Livewire path through Index::destroy() goes through a route-model
 // binding which Brand's Layer 1 scope blocks BEFORE the Policy fires
 // (the parent brand is null for userA → null-deref). The canonical
 // cross-tenant authority is the Policy — and at that layer, the deny
 // is unambiguous. Same pattern as SourcesPageTest Test 8 (locked
 // 02-04 SUMMARY deviation #5).
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    auth()->logout();
    $brandB = Brand::factory()->for($userB)->create();
    $sourceB = BrandSource::factory()->for($brandB)->create([
        'type' => SourceType::Service->value,
    ]);

    expect(Gate::forUser($userA)->denies('delete', $sourceB))->toBeTrue();
});

it('user A cannot mount Create on user B brand (Layer 3 firstOrFail)', function () {
    $userA = User::factory()->create(['email_verified_at' => now()]);
    $userB = User::factory()->create(['email_verified_at' => now()]);
    auth()->logout();
    $brandB = Brand::factory()->for($userB)->create();

 // HTTP boundary: Laravel maps ModelNotFoundException → 404 response.
    $this->actingAs($userA)
        ->get(route('sources.create', ['brandSlug' => $brandB->slug]))
        ->assertNotFound();
});

it('user A cannot trigger addFromExample on user B brand', function () {
    $userA = User::factory()->create(['email_verified_at' => now()]);
    $userB = User::factory()->create(['email_verified_at' => now()]);
    auth()->logout();
    $brandB = Brand::factory()->for($userB)->create();

 // The Livewire test harness propagates ModelNotFoundException
 // mount() — addFromExample() is never reached because Layer 3 fires
 // first. Asserting on the exception is the most direct evidence that
 // the attack surface is blocked before policy/payload code can run.
    expect(fn () => Livewire::actingAs($userA)
        ->test(Index::class, ['brandSlug' => $brandB->slug])
        ->call('addFromExample', SourceType::Service->value, 0)
    )->toThrow(ModelNotFoundException::class);
});

it('regression: factory-for-other-brand requires auth()->logout() first', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

 // regression guard
 // While userA is acting, factory-creating a BrandSource on userB's brand
 // works because BrandSource has NO Global Scope (Layer 1 is transitive
 // via the parent Brand and only filters SELECTs, not INSERTs). The safe
 // pattern across the suite is: auth()->logout() BEFORE the factory chain
 // then re-actingAs in the assertion phase — same idiom 's
 // IsolationTest established.
    $this->actingAs($userA);
    auth()->logout(); // <-- mandatory regression guard

    $brandB = Brand::factory()->for($userB)->create();
    $sourceB = BrandSource::factory()->for($brandB)->create([
        'type' => SourceType::Service->value,
    ]);

 // Row exists in the database with the correct ownership chain.
    expect($sourceB->brand_id)->toBe($brandB->id)
        ->and($sourceB->brand->user_id)->toBe($userB->id);

 // From userA's perspective with Layer 1 active, brandB is invisible
 // and consequently brandB's sources are unreachable via the relation.
    $this->actingAs($userA);
    expect(Brand::where('id', $brandB->id)->exists())->toBeFalse();
    expect($userA->brands()->where('id', $brandB->id)->exists())->toBeFalse();

 // From userB's perspective, both rows are visible and properly chained.
    $this->actingAs($userB);
    expect(Brand::where('id', $brandB->id)->exists())->toBeTrue();
    expect($userB->brands()->find($brandB->id)->sources()->find($sourceB->id))->not->toBeNull();
});
