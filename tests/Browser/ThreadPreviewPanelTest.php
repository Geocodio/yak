<?php

use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

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

test('a ready preview shows its host on a phone and a Live pill after a reload', function () {
    config(['yak.deployments.hostname_suffix' => 'yak.example.com']);
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create(['sandbox_state' => ThreadSandboxState::Running]);
    Cache::put("thread-preview:state:{$thread->id}", 'ready');
    $this->actingAs($owner);

    visit('/threads/' . $thread->id)->on()->mobile()
        ->click('[data-testid="thread-phone-tab-preview"]')
        ->assertVisible('[data-testid="preview-host"]')
        ->assertMissing('[data-testid="preview-live-pill"]')
        ->click('[data-testid="preview-reload"]')
        ->assertVisible('[data-testid="preview-live-pill"]')
        ->assertNoJavaScriptErrors();
});

test('a failing preview offers retry, ask Claude and the manifest', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create(['sandbox_state' => ThreadSandboxState::Running]);
    Cache::put("thread-preview:state:{$thread->id}", 'failing');
    Cache::put("thread-preview:manifest:{$thread->id}", ['port' => 3000, 'coldStart' => 'npm run dev']);
    $this->actingAs($owner);

    visit('/threads/' . $thread->id)
        ->click('[data-testid="thread-dock-toggle-preview"]')
        ->assertSee('The app did not answer on port 3000')
        ->assertVisible('[data-testid="preview-retry"]')
        ->assertVisible('[data-testid="preview-ask-claude"]')
        ->assertVisible('[data-testid="preview-edit-manifest"]')
        ->assertNoJavaScriptErrors();
});

test('a repository without a manifest shows the sample file', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create(['sandbox_state' => ThreadSandboxState::Running]);
    Cache::put("thread-preview:state:{$thread->id}", 'no_manifest');
    $this->actingAs($owner);

    visit('/threads/' . $thread->id)
        ->click('[data-testid="thread-dock-toggle-preview"]')
        ->assertSee('Tell Yak how to run this app')
        ->assertVisible('[data-testid="preview-sample-manifest"]')
        ->assertVisible('[data-testid="preview-ask-claude-add"]')
        ->assertNoJavaScriptErrors();
});

test('a thread on your computer explains teleport', function () {
    $thread = Thread::factory()->ownedByDevice()->create();
    $this->actingAs($thread->ownerDevice->user);

    visit('/threads/' . $thread->id)
        ->click('[data-testid="thread-dock-toggle-preview"]')
        ->assertSee('This thread runs on your computer')
        ->assertSee('How teleport works')
        ->assertNoJavaScriptErrors();
});
