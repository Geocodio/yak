<?php

use App\Enums\ThreadOwnerSide;
use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadTurns;

function composerThread(): array
{
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);
    YakTask::factory()->create(['thread_id' => $thread->id]);

    return [$user, $thread];
}

it('sends with the picked model', function () {
    config(['claude-accounts.models' => ['sonnet', 'opus']]);
    [$user, $thread] = composerThread();
    $this->actingAs($user);
    $this->mock(ThreadTurns::class, function ($mock) {
        $mock->shouldReceive('start')->once()->withArgs(fn ($task, $text, $sender, $model) => $text === 'Hello' && $model === 'opus')->andReturn(new TaskRun);
    });

    $page = visit("/threads/{$thread->id}");
    $page->click('[data-testid="composer-model"]')
        ->click('[data-testid="composer-model-opus"]')
        ->assertSeeIn('[data-testid="composer-model"]', 'opus')
        ->fill('[data-testid="thread-composer-input"]', 'Hello')
        ->click('[data-testid="thread-send"]');
    $page->wait(3)->assertNoJavaScriptErrors();
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
