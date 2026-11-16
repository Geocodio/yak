<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

beforeEach(function () {
    Route::middleware(['web', 'auth'])->get('/test/publish-gate/{required}', fn (string $required) => Inertia::render('Threads/PublishGateHarness', ['required' => $required === '1']));
    $this->actingAs(User::factory()->create());
});

test('a private thread asks before opening the PR, and cancel does nothing', function () {
    visit('/test/publish-gate/1')
        ->click('[data-testid="stub-open-pr"]')
        ->assertVisible('[data-testid="private-publish-dialog"]')
        ->assertSee('Opening a pull request publishes its branch')
        ->assertSeeIn('[data-testid="confirmed-count"]', '0')
        ->click('Cancel')
        ->assertMissing('[data-testid="private-publish-dialog"]')
        ->assertSeeIn('[data-testid="confirmed-count"]', '0')
        ->click('[data-testid="stub-open-pr"]')
        ->click('[data-testid="private-publish-confirm"]')
        ->assertMissing('[data-testid="private-publish-dialog"]')
        ->assertSeeIn('[data-testid="confirmed-count"]', '1')
        ->assertNoJavaScriptErrors();
});

test('a team thread opens the PR directly', function () {
    visit('/test/publish-gate/0')
        ->click('[data-testid="stub-open-pr"]')
        ->assertMissing('[data-testid="private-publish-dialog"]')
        ->assertSeeIn('[data-testid="confirmed-count"]', '1')
        ->assertNoJavaScriptErrors();
});
