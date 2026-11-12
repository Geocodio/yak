<?php

use App\Enums\ThreadOwnerSide;
use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\ClaudeAccounts\ClaudeModelCatalog;
use App\Services\Threads\ThreadBusy;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\Cache;

function composerThread(): array
{
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);
    YakTask::factory()->create(['thread_id' => $thread->id]);

    return [$user, $thread];
}

it('sends with the picked model', function () {
    [$user, $thread] = composerThread();
    $this->actingAs($user);
    $this->mock(ThreadTurns::class, function ($mock) {
        $mock->shouldReceive('start')->once()->withArgs(fn ($task, $text, $sender, $model) => $text === 'Hello' && $model === 'opus')->andReturn(new TaskRun);
    });

    $page = visit("/threads/{$thread->id}");
    $page->click('[data-testid="composer-model"]')
        ->click('[data-testid="composer-model-opus"]')
        ->assertSeeIn('[data-testid="composer-model"]', 'Opus 5.5')
        ->fill('[data-testid="thread-composer-input"]', 'Hello')
        ->click('[data-testid="thread-send"]');
    $page->wait(3)->assertNoJavaScriptErrors();
});

it('picks a family, then pins an older version from the expanded family', function () {
    Cache::forever(ClaudeModelCatalog::CACHE_KEY, [
        ['id' => 'claude-opus-5-5', 'family' => 'opus', 'label' => 'Opus 5.5', 'createdAt' => '2026-05-01T00:00:00Z'],
        ['id' => 'claude-opus-5', 'family' => 'opus', 'label' => 'Opus 5', 'createdAt' => '2026-02-01T00:00:00Z'],
        ['id' => 'claude-sonnet-5-5', 'family' => 'sonnet', 'label' => 'Sonnet 5.5', 'createdAt' => '2026-05-01T00:00:00Z'],
        ['id' => 'claude-sonnet-5', 'family' => 'sonnet', 'label' => 'Sonnet 5', 'createdAt' => '2026-02-01T00:00:00Z'],
    ]);
    [$user, $thread] = composerThread();
    $this->actingAs($user);

    $page = visit("/threads/{$thread->id}");
    $page->click('[data-testid="composer-model"]')
        ->click('[data-testid="composer-model-opus"]')
        ->assertSeeIn('[data-testid="composer-model"]', 'Opus 5.5')
        ->click('[data-testid="composer-model"]')
        ->click('[data-testid="composer-model-expand-sonnet"]')
        ->assertPresent('[data-testid="composer-model-claude-sonnet-5"]')
        ->assertMissing('[data-testid="composer-model-claude-opus-5"]')
        ->click('[data-testid="composer-model-expand-opus"]')
        ->assertMissing('[data-testid="composer-model-claude-sonnet-5"]')
        ->click('[data-testid="composer-model-claude-opus-5"]')
        ->assertSeeIn('[data-testid="composer-model"]', 'Opus 5')
        ->assertSeeIn('[data-testid="composer-model"]', 'pinned')
        ->assertNoJavaScriptErrors();
});

it('Send looks enabled with text and dimmed when empty', function () {
    [$user, $thread] = composerThread();
    $this->actingAs($user);

    $page = visit("/threads/{$thread->id}");
    $page->assertScript("getComputedStyle(document.querySelector('[data-testid=\"thread-send\"]')).opacity !== '1'", true);
    $page->fill('[data-testid="thread-composer-input"]', 'Hello');
    $page->assertScript("getComputedStyle(document.querySelector('[data-testid=\"thread-send\"]')).opacity", '1');
});

it('shows Stop only while Claude works and posts the stop', function () {
    [$user, $thread] = composerThread();
    $this->actingAs($user);

    visit("/threads/{$thread->id}")->assertMissing('[data-testid="thread-stop"]');

    TaskRun::factory()->create(['yak_task_id' => $thread->tasks()->first()->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::Running]);
    $this->mock(ThreadTurns::class, function ($mock) {
        $mock->shouldReceive('stop')->once();
    });

    $page = visit("/threads/{$thread->id}");
    $page->assertVisible('[data-testid="thread-stop"]');
    $page->script("document.querySelector('[data-testid=\"thread-stop\"]').click()");
    $page->wait(3)->assertNoJavaScriptErrors();
});

it('explains why the composer is disabled', function (array $attributes, string $copy) {
    [$user, $thread] = composerThread();
    $thread->update($attributes);
    $this->actingAs($user);

    visit("/threads/{$thread->id}")
        ->assertSee($copy)
        ->assertScript("document.querySelector('[data-testid=\"thread-composer-input\"]').disabled", true)
        ->assertScript("document.querySelector('[data-testid=\"thread-send\"]').disabled", true);
})->with([
    'closed' => [['status' => 'closed'], 'This thread is closed.'],
    'computer owns it' => [['owner_side' => ThreadOwnerSide::Device], 'Your computer is working on this thread'],
]);

it('keeps the draft and shows the error for an overlong message', function () {
    [$user, $thread] = composerThread();
    $this->actingAs($user);

    $page = visit("/threads/{$thread->id}");
    $page->script("const t = document.querySelector('[data-testid=\"thread-composer-input\"]'); Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value').set.call(t, 'a'.repeat(20001)); t.dispatchEvent(new Event('input', {bubbles: true}))");
    $page->wait(1)->script("document.querySelector('[data-testid=\"thread-send\"]').click()");
    $page->wait(5)->assertVisible('[data-testid="thread-composer-error"]')
        ->assertScript("document.querySelector('[data-testid=\"thread-composer-input\"]').value.length", 20001);
});

it('keeps the composer enabled on a waiting thread and a sent message shows as not read yet', function () {
    [$user, $thread] = composerThread();
    $thread->update(['blocked_reason' => 'waiting_for_account']);
    TaskRun::factory()->create(['yak_task_id' => $thread->tasks()->first()->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::WaitingForAccount]);
    $this->actingAs($user);

    $page = visit("/threads/{$thread->id}");
    $page->assertAttribute('[data-testid="thread-composer-input"]', 'placeholder', 'Message Claude. It reads this when its turn starts.')
        ->assertSeeIn('[data-testid="thread-composer-hint"]', 'Claude reads it when its turn starts.')
        ->fill('[data-testid="thread-composer-input"]', 'Hello')
        ->click('[data-testid="thread-send"]');
    $page->wait(3)->assertVisible('[data-testid="thread-message-pending"]')->assertNoJavaScriptErrors();
});

it('shows a refused message with its reason and puts the text back on Edit', function () {
    [$user, $thread] = composerThread();
    $this->actingAs($user);
    $this->mock(ThreadTurns::class, function ($mock) {
        $mock->shouldReceive('start')->once()->andThrow(new ThreadBusy('busy'));
    });

    $page = visit("/threads/{$thread->id}");
    $page->fill('[data-testid="thread-composer-input"]', 'Hello')
        ->click('[data-testid="thread-send"]');
    $page->wait(2)
        ->assertSeeIn('[data-testid="thread-message-unsent"]', 'Hello')
        ->assertSeeIn('[data-testid="thread-message-unsent-status"]', 'Not sent. This thread is already starting a turn.')
        ->assertScript("document.querySelector('[data-testid=\"thread-composer-input\"]').value", '')
        ->click('[data-testid="thread-message-unsent-edit"]');
    $page->wait(1)
        ->assertMissing('[data-testid="thread-message-unsent"]')
        ->assertScript("document.querySelector('[data-testid=\"thread-composer-input\"]').value", 'Hello')
        ->assertNoJavaScriptErrors();
});
