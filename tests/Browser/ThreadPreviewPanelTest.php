<?php

use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Models\User;

test('a thread with a stopped sandbox shows the waking state in the Preview panel', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create(['sandbox_state' => ThreadSandboxState::Stopped]);
    $this->actingAs($owner);

    visit('/threads/' . $thread->id)
        ->click('[data-testid="thread-dock-toggle-preview"]')
        ->assertVisible('[data-testid="preview-panel"]')
        ->assertVisible('[data-testid="preview-state-waking"]')
        ->assertMissing('[data-testid="preview-frame"]')
        ->assertNoJavaScriptErrors();
});

test('the toolbar can open the preview in a new tab once it is ready', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create(['sandbox_state' => ThreadSandboxState::Running]);
    $this->actingAs($owner);

    visit('/threads/' . $thread->id)
        ->click('[data-testid="thread-dock-toggle-preview"]')
        ->assertVisible('[data-testid="preview-panel"]')
        ->assertNoJavaScriptErrors();
});
