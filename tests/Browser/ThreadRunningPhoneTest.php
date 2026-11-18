<?php

use App\Enums\RunnerState;
use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\YakTask;

/**
 * A thread with a running turn that has made `$steps` Bash calls, and a first reply of text.
 *
 * @return array{0: Thread, 1: TaskRun}
 */
function runningStepsThread(int $steps): array
{
    $thread = Thread::factory()->create(['created_by_user_id' => auth()->id(), 'owner_user_id' => auth()->id(), 'runner_state' => RunnerState::Busy]);
    $task = YakTask::factory()->running()->create(['thread_id' => $thread->id, 'repo' => $thread->repo, 'started_at' => now()->subMinutes(10)]);
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::Running, 'started_at' => now()->subMinutes(10)]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 1, 'type' => 'turn', 'payload' => ['turnId' => $run->id, 'state' => 'running']]);
    $sequence = 2;

    foreach (range(1, $steps) as $index) {
        ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => $sequence, 'type' => 'entry', 'payload' => ['role' => 'assistant', 'messageId' => "m{$index}", 'blocks' => [['type' => 'text', 'text' => ''], ['type' => 'tool_use', 'id' => "t{$index}", 'name' => 'Bash', 'input' => ['command' => "echo {$index}"]]]]]);
        ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => $sequence + 1, 'type' => 'entry', 'payload' => ['role' => 'tool_result', 'toolUseId' => "t{$index}", 'isError' => false, 'text' => (string) $index]]);
        $sequence += 2;
    }

    $thread->forceFill(['event_sequence' => $sequence - 1])->save();

    return [$thread, $run];
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('lays a running thread out as tabs on a phone, with the overview behind its own tab', function () {
    [$thread] = runningStepsThread(6);

    $page = visit(route('threads.show', $thread))->on()->mobile()
        ->assertVisible('[data-testid="thread-phone-tabs"]')
        ->assertVisible('[data-testid="thread-conversation-scroller"]')
        ->assertVisible('[data-testid="thread-composer"]')
        ->assertMissing('[data-testid="thread-sidebar"]:visible')
        ->assertCount('[data-testid="thread-tool-activity"]', 1)
        ->click('[data-testid="thread-phone-tab-overview"]')
        ->assertVisible('[data-testid="thread-sidebar"]')
        ->assertNoJavaScriptErrors();

    expect($page->script('window.innerWidth'))->toBe(375)
        ->and($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
});

it('lets a phone open the steps of a long run without leaving the conversation', function () {
    [$thread] = runningStepsThread(120);

    $page = visit(route('threads.show', $thread))->on()->mobile()
        ->assertSee('Ran 120 commands')
        ->click('[data-testid="step-group"] > summary')
        ->assertSee('echo 120')
        ->assertNoJavaScriptErrors();

    expect($page->script('window.innerWidth'))->toBe(375)
        ->and($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue()
        ->and($page->script('document.querySelectorAll(\'[data-testid="step-row"]\').length'))->toBeGreaterThan(100);
});

it('keeps the conversation out from under the side panel on a short viewport', function () {
    [$thread] = runningStepsThread(40);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 200, 'type' => 'entry', 'payload' => ['role' => 'assistant', 'messageId' => 'answer', 'blocks' => [['type' => 'text', 'text' => 'Done', 'html' => '<p>' . str_repeat('A long answer that fills the width of the conversation. ', 6) . '</p>']]]]);

    $page = visit(route('threads.show', $thread))->on()->macbookAir()->assertVisible('[data-testid="thread-claude-message"]');

    /** @var array{messageRight: float, asideLeft: float} $rects */
    $rects = $page->script(
        '(() => {'
        . 'const message = document.querySelector(\'[data-testid="thread-claude-message"]\');'
        . 'const aside = document.querySelector(\'[data-testid="thread-sidebar"]\');'
        . 'return { messageRight: message.getBoundingClientRect().right, asideLeft: aside.getBoundingClientRect().left };'
        . '})()'
    );

    expect($rects['messageRight'])->toBeLessThanOrEqual($rects['asideLeft']);
});

it('collapses the composer to one line on a phone until it is focused', function () {
    [$thread] = runningStepsThread(2);

    // A phone-width `resize()` rather than `->on()->mobile()`: the layout only depends on viewport width,
    // and the device emulation's touch input does not focus a textarea on `click()` in this plugin version.
    $page = visit(route('threads.show', $thread));
    $page->resize(375, 812);

    $collapsedHeight = $page->script('document.querySelector(\'[data-testid="thread-composer"]\').getBoundingClientRect().height');
    expect($collapsedHeight)->toBeLessThan(80);

    $page->click('[data-testid="thread-composer-input"]');
    $expandedHeight = $page->script('document.querySelector(\'[data-testid="thread-composer"]\').getBoundingClientRect().height');
    expect($expandedHeight)->toBeGreaterThan($collapsedHeight + 40);
    $page->assertVisible('[data-testid="thread-send"]');
})->todo('The thread composer is a fixed two-row box with its toolbar on a phone; the task composer was one line until focused.');
