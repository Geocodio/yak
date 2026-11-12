<?php

use App\Models\Thread;
use App\Models\User;

test('journey 8: share a private thread, the teammate reads it, the owner takes it back', function () {
    $owner = User::factory()->create();
    $teammate = User::factory()->create(['name' => 'Tessa Teammate']);
    $thread = Thread::factory()->private($owner)->create(['title' => 'Quarterly pricing notes']);
    $threadUrl = '/threads/' . $thread->id;

    $this->actingAs($owner);
    visit($threadUrl)
        ->assertVisible('[data-testid="private-banner"]')
        ->click('[data-testid="share-button"]')
        ->click('[data-testid="share-mode-people"]')
        ->click('[data-testid="share-person-' . $teammate->id . '"]')
        ->click('[data-testid="share-submit"]')
        ->assertMissing('[data-testid="share-dialog"]')
        ->assertSeeIn('[data-testid="visibility-badge"]', 'Shared')
        ->assertNoJavaScriptErrors();

    $this->actingAs($teammate);
    visit('/tasks')
        ->click('[data-testid="thread-filter"] button[data-testid="thread-filter-everyone"]')
        ->assertVisible('[data-testid="thread-row-' . $thread->id . '"]')
        ->click('[data-testid="thread-row-' . $thread->id . '"]')
        ->assertPathIs($threadUrl)
        ->assertSee('Quarterly pricing notes')
        ->assertNoJavaScriptErrors();

    $this->actingAs($owner);
    visit($threadUrl)
        ->click('[data-testid="share-button"]')
        ->click('[data-testid="share-mode-only_me"]')
        ->click('[data-testid="share-submit"]')
        ->assertMissing('[data-testid="share-dialog"]')
        ->assertVisible('[data-testid="private-banner"]');

    $this->actingAs($teammate);
    $this->get($threadUrl)->assertNotFound();
    expect(json_encode($this->getJson('/threads/list?filter=everyone')->assertOk()->json()))->not->toContain('Quarterly pricing notes');
    expect(json_encode($this->getJson('/threads/list?search=Quarterly')->assertOk()->json()))->not->toContain('Quarterly pricing notes');
    visit('/tasks')
        ->assertMissing('[data-testid="thread-row-' . $thread->id . '"]');
});
