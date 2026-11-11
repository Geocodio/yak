<?php

use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadTurns;

function composerThread(): array
{
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);

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
    $page->script("document.querySelector('[data-testid=\"composer-model\"]').click()");
    $page->script("document.querySelector('[data-testid=\"composer-model-opus\"]').click()");
    $page->type('[data-testid="thread-composer-input"]', 'Hello');
    $page->script("document.querySelector('[data-testid=\"thread-send\"]').click()");
    $page->wait(8)->assertNoJavaScriptErrors();
});

it('Send looks enabled with text and dimmed when empty', function () {
    [$user, $thread] = composerThread();
    $this->actingAs($user);

    $page = visit("/threads/{$thread->id}");
    $page->assertScript("getComputedStyle(document.querySelector('[data-testid=\"thread-send\"]')).opacity !== '1'", true);
    $page->type('[data-testid="thread-composer-input"]', 'Hello');
    $page->assertScript("getComputedStyle(document.querySelector('[data-testid=\"thread-send\"]')).opacity", '1');
});

it('shows Stop only while Claude works', function () {
    [$user, $thread] = composerThread();
    $this->actingAs($user);

    visit("/threads/{$thread->id}")->assertMissing('[data-testid="thread-stop"]');
});

it('keeps the draft and shows the error for an overlong message', function () {
    [$user, $thread] = composerThread();
    $this->actingAs($user);

    $page = visit("/threads/{$thread->id}");
    $page->script("const t = document.querySelector('[data-testid=\"thread-composer-input\"]'); Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value').set.call(t, 'a'.repeat(20001)); t.dispatchEvent(new Event('input', {bubbles: true}))");
    $page->script("document.querySelector('[data-testid=\"thread-send\"]').click()");
    $page->wait(5)->assertVisible('[data-testid="thread-composer-error"]')
        ->assertScript("document.querySelector('[data-testid=\"thread-composer-input\"]').value.length", 20001);
});
