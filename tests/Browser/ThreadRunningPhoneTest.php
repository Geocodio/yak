<?php

use App\Enums\RunnerState;
use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadTurns;

require_once __DIR__ . '/../Support/NewPageFixtures.php';

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

it('keeps the composer and its Send button on screen at 375 px with a card above it', function () {
    $this->actingAs(User::factory()->create());
    $thread = seedStopCardThread();

    $page = visit(route('threads.show', $thread));
    $page->resize(375, 812)->assertVisible('[data-testid="card-stop"]')->assertVisible('[data-testid="thread-send"]');

    $fits = $page->script(<<<'JS'
        (async () => {
            const measure = () => ['thread-composer', 'thread-composer-input', 'thread-send'].map((testId) => {
                const rect = document.querySelector('[data-testid="' + testId + '"]').getBoundingClientRect();
                return [testId, rect.top >= 0 && rect.bottom <= window.innerHeight + 1 && rect.left >= 0 && rect.right <= window.innerWidth + 1];
            });
            for (let attempt = 0; attempt < 60 && ! measure().every(([, fits]) => fits); attempt++) {
                await new Promise((resolve) => setTimeout(resolve, 50));
            }

            return measure();
        })()
    JS);

    expect($fits)->toBe([['thread-composer', true], ['thread-composer-input', true], ['thread-send', true]]);
});

it('keeps the composer and its Send button on screen at 375 x 667 with a stop card above it', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    $thread = seedStopCardThread();

    $page = visit(route('threads.show', $thread));
    $page->resize(375, 667)->assertVisible('[data-testid="card-stop"]')->assertVisible('[data-testid="thread-send"]');

    $fits = $page->script(<<<'JS'
        (async () => {
            const measure = () => ['thread-composer', 'thread-composer-input', 'thread-send'].map((testId) => {
                const rect = document.querySelector('[data-testid="' + testId + '"]').getBoundingClientRect();
                return [testId, rect.top >= 0 && rect.bottom <= window.innerHeight + 1 && rect.left >= 0 && rect.right <= window.innerWidth + 1];
            });
            for (let attempt = 0; attempt < 60 && ! measure().every(([, fits]) => fits); attempt++) {
                await new Promise((resolve) => setTimeout(resolve, 50));
            }

            return measure();
        })()
    JS);

    expect($fits)->toBe([['thread-composer', true], ['thread-composer-input', true], ['thread-send', true]])
        ->and($page->script('document.documentElement.scrollHeight <= window.innerHeight + 1'))->toBeTrue();

    $page->assertVisible('[data-testid="stop-retry"]')->click('[data-testid="stop-retry"]')->assertNoJavaScriptErrors();
});

it('draws the phone thread as the boards do: tabs, header line, card in the scroller, one-line composer, no tab bar', function () {
    $thread = seedStopCardThread();

    $page = visit(route('threads.show', $thread));
    $page->resize(390, 844)->assertVisible('[data-testid="card-stop"]')->assertMissing('[data-testid="mobile-tab-bar"]:visible');

    $measure = <<<'JS'
        (async () => {
            const box = (selector) => document.querySelector(selector).getBoundingClientRect();
            const settled = async () => { for (let i = 0; i < 60 && box('[data-testid="thread-composer-input"]').height > 60; i++) { await new Promise((r) => setTimeout(r, 50)); } };
            await settled();
            const tabs = [...document.querySelectorAll('[data-testid^="thread-phone-tab-"]:not([data-testid$="dot"])')].map((tab) => tab.textContent.trim());
            const scroller = document.querySelector('[data-testid="thread-conversation-scroller"]');
            return {
                tabs,
                cardInScroller: scroller.contains(document.querySelector('[data-testid="card-stop"]')),
                buttonHeights: [...document.querySelectorAll('[data-testid="card-stop"] button')].map((button) => Math.round(button.getBoundingClientRect().height)),
                composerHeight: Math.round(box('[data-testid="thread-composer"]').height),
                toolsHidden: getComputedStyle(document.querySelector('[data-testid="thread-composer-tools-row"]')).display === 'none',
                pill: document.querySelector('[data-testid="thread-phone-pill"]')?.textContent,
                overflow: document.documentElement.scrollWidth > window.innerWidth,
            };
        })()
    JS;
    $state = $page->script($measure);

    expect(array_slice($state['tabs'], 0, 2))->toBe(['Chat', 'Overview'])
        ->and($state['cardInScroller'])->toBeTrue()
        ->and($state['buttonHeights'])->each->toBeGreaterThanOrEqual(44)
        ->and($state['composerHeight'])->toBeLessThan(110)
        ->and($state['toolsHidden'])->toBeTrue()
        ->and($state['pill'])->toBe('Stopped')
        ->and($state['overflow'])->toBeFalse();

    $page->click('[data-testid="thread-composer-input"]')->assertVisible('[data-testid="composer-model"]')->assertVisible('[data-testid="thread-send"]');
});

it('keeps the way back to the thread list and the other sections on a thread page without the tab bar', function () {
    $thread = seedStopCardThread();

    $page = visit(route('threads.show', $thread));
    $page->resize(390, 844)->assertVisible('[data-testid="mobile-nav-toggle"]')->click('[data-testid="mobile-nav-toggle"]')
        ->assertVisible('[data-testid="mobile-nav"]')
        ->assertVisible('[data-testid="mobile-section-switcher"]')
        ->assertSeeIn('[data-testid="mobile-section-switcher"]', 'Operations')
        ->assertNoJavaScriptErrors();
});

it('reaches the composer tools from the keyboard: focusing the field reveals them in tab order', function () {
    $thread = seedStopCardThread();

    $page = visit(route('threads.show', $thread));
    $page->resize(390, 844)->assertVisible('[data-testid="thread-send"]');

    $state = $page->script(<<<'JS'
        (async () => {
            const tools = document.querySelector('[data-testid="thread-composer-tools-row"]');
            const before = getComputedStyle(tools).display;
            document.querySelector('[data-testid="thread-composer-input"]').focus();
            for (let attempt = 0; attempt < 40 && getComputedStyle(tools).display === 'none'; attempt++) {
                await new Promise((resolve) => setTimeout(resolve, 50));
            }

            return { before, after: getComputedStyle(tools).display, hasFocusable: tools.querySelectorAll('button').length > 0 };
        })()
    JS);

    expect($state['before'])->toBe('none')->and($state['after'])->not->toBe('none')->and($state['hasFocusable'])->toBeTrue();
});

it('keeps a queued message, the textarea and Send on separate rows inside the phone composer', function () {
    require_once __DIR__ . '/../Support/ThreadTurnHelpers.php';
    require_once __DIR__ . '/../Support/ThreadTurnsHelpers.php';
    $user = auth()->user();
    [$thread] = runningTurnFor();
    YakTask::factory()->create(['thread_id' => $thread->id]);
    $thread->forceFill(['model' => 'sonnet', 'runner_state' => RunnerState::Starting, 'created_by_user_id' => $user->id, 'owner_user_id' => $user->id])->save();
    app(ThreadTurns::class)->steer($thread, 'add docs afterwards', $user, null, source: 'thread', isQueueOnly: true);

    $page = visit(route('threads.show', $thread));
    $page->resize(375, 667)->assertVisible('[data-testid="thread-queued-messages"]')->assertVisible('[data-testid="thread-send"]');

    $inside = $page->script(<<<'JS'
        (async () => {
            const inside = () => ['thread-composer-input', 'thread-send'].every((testId) => {
                const rect = document.querySelector('[data-testid="' + testId + '"]').getBoundingClientRect();
                return rect.left >= 0 && rect.right <= window.innerWidth;
            }) && document.documentElement.scrollWidth <= window.innerWidth;
            for (let attempt = 0; attempt < 60 && ! inside(); attempt++) {
                await new Promise((resolve) => setTimeout(resolve, 50));
            }

            return inside();
        })()
    JS);

    expect($inside)->toBeTrue();
});
