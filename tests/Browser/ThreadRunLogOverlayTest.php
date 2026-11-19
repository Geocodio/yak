<?php

use App\Enums\TaskStatus;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\YakTask;

/**
 * A thread with one finished run whose steps are Bash calls with their results. The steps get the entry
 * sequence numbers 2, 4, 6 and so on; the result of each follows it.
 *
 * @param  list<string>  $commands
 * @param  array<int, ?string>  $outputs  the result text by step index, null for a step still running
 * @return array{0: Thread, 1: TaskRun, 2: list<int>}
 */
function overlayThread(array $commands, array $outputs = [], string $status = 'success'): array
{
    $thread = Thread::factory()->create(['created_by_user_id' => auth()->id(), 'owner_user_id' => auth()->id()]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'repo' => $thread->repo, 'status' => TaskStatus::from($status), 'pr_url' => null, 'started_at' => now()]);
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => $status === 'running' ? 'running' : 'completed', 'started_at' => now()->subHour()]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 1, 'type' => 'turn', 'payload' => ['turnId' => $run->id, 'state' => 'running']]);
    $ids = [];
    $sequence = 2;

    foreach ($commands as $index => $command) {
        $ids[] = $sequence;
        ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => $sequence, 'type' => 'entry', 'payload' => ['role' => 'assistant', 'blocks' => [['type' => 'tool_use', 'id' => "toolu_{$index}", 'name' => 'Bash', 'input' => ['command' => $command]]]]]);
        $output = array_key_exists($index, $outputs) ? $outputs[$index] : (string) ($index + 1);

        if ($output !== null) {
            ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => $sequence + 1, 'type' => 'entry', 'payload' => ['role' => 'tool_result', 'toolUseId' => "toolu_{$index}", 'isError' => false, 'text' => $output]]);
        }

        $sequence += 2;
    }

    $thread->forceFill(['event_sequence' => $sequence - 1])->save();

    return [$thread, $run, $ids];
}

/**
 * A thread with two readable steps, the one most tests open.
 *
 * @return array{0: Thread, 1: TaskRun, 2: list<int>}
 */
function overlayTwoStepThread(): array
{
    return overlayThread(['grep -n calculateDistance lib/Geocodio/AddressParser/Locale/US/Parser.php', 'vendor/bin/phpstan analyse --memory-limit=2G --no-progress'], ['line 758', "Note: Using configuration file phpstan.neon.\n\n [OK] No errors"]);
}

const OVERLAY = '[data-testid="transcript-overlay"]:visible';

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('opens the log overlay on the entry a deep link names', function () {
    [$thread, , $ids] = overlayTwoStepThread();

    visit(route('threads.show', ['thread' => $thread, 'log' => $ids[1]]))
        ->assertVisible(OVERLAY)
        ->assertSee('vendor/bin/phpstan analyse')
        ->assertSee('Step 2 of 2')
        ->assertNoJavaScriptErrors();
});

it('keeps the overlay the same width whichever entry is open', function () {
    [$thread, , $ids] = overlayTwoStepThread();

    $page = visit(route('threads.show', ['thread' => $thread, 'log' => $ids[0]]))->assertVisible(OVERLAY);
    $widthOnFirst = $page->script('document.querySelector(\'[data-testid="transcript-overlay"]\').offsetWidth');

    $page->click('[data-testid="log-next"]')->assertSee('Step 2 of 2');
    $widthOnSecond = $page->script('document.querySelector(\'[data-testid="transcript-overlay"]\').offsetWidth');

    expect($widthOnSecond)->toBe($widthOnFirst);
});

it('steps the overlay with the keyboard and closes it with escape', function () {
    [$thread] = overlayTwoStepThread();

    visit(route('threads.show', $thread))
        ->click('[data-testid="run-log-button"]')
        ->assertVisible(OVERLAY)
        ->assertSee('Step 1 of 2')
        ->keys('[data-testid="transcript-overlay"]', ['ArrowRight'])
        ->assertSee('Step 2 of 2')
        ->keys('[data-testid="transcript-overlay"]', ['Escape'])
        ->assertMissing(OVERLAY)
        ->assertNoJavaScriptErrors();
});

it('scrolls the rail to the opened entry', function () {
    [$thread, , $ids] = overlayThread(array_map(fn (int $number): string => "echo {$number}", range(1, 40)));

    $page = visit(route('threads.show', ['thread' => $thread, 'log' => $ids[34]]))
        ->assertVisible(OVERLAY)
        ->assertSee('Step 35 of 40');

    $selectedIsInView = $page->script(<<<'JS'
        (() => {
            const overlay = document.querySelector('[data-testid="transcript-overlay"]');
            const row = overlay.querySelector('[data-testid="log-entry-open"]');
            const scroller = row.closest('[data-scroller]') ?? row.parentElement;
            const r = row.getBoundingClientRect();
            const s = scroller.getBoundingClientRect();
            return r.top >= s.top - 1 && r.bottom <= s.bottom + 1;
        })()
    JS);

    expect($selectedIsInView)->toBeTrue();
});

it('opens the rail scrolled to a deep link that arrives on a later page of the log', function () {
    [$thread, , $ids] = overlayThread(array_map(fn (int $number): string => "echo {$number}", range(1, 140)));

    $page = visit(route('threads.show', ['thread' => $thread, 'log' => $ids[129]]))
        ->assertVisible(OVERLAY)
        ->assertSee('Step 130 of');

    $selectedIsInView = $page->script(<<<'JS'
        (() => {
            const overlay = document.querySelector('[data-testid="transcript-overlay"]');
            const row = overlay.querySelector('[data-log-selected]');
            const scroller = row.closest('[data-scroller]');
            const r = row.getBoundingClientRect();
            const s = scroller.getBoundingClientRect();
            return r.top >= s.top - 1 && r.bottom <= s.bottom + 1;
        })()
    JS);

    expect($selectedIsInView)->toBeTrue();
});

it('does not fetch the log again when stepping back to an entry already seen', function () {
    [$thread] = overlayTwoStepThread();

    $page = visit(route('threads.show', $thread))
        ->click('[data-testid="run-log-button"]')
        ->assertVisible(OVERLAY)
        ->assertSee('grep -n calculateDistance');

    $page->click('[data-testid="log-next"]')->assertSee('vendor/bin/phpstan analyse');
    $requestsAfterTwo = $page->script('performance.getEntriesByType("resource").filter((entry) => entry.name.includes("/log")).length');

    $page->click('[data-testid="log-prev"]')->assertSee('grep -n calculateDistance');
    $requestsAfterThree = $page->script('performance.getEntriesByType("resource").filter((entry) => entry.name.includes("/log")).length');

    expect($requestsAfterTwo)->toBeGreaterThan(0);
    expect($requestsAfterThree)->toBe($requestsAfterTwo);
});

it('shows the output of a step that was still running once it arrives, on the step that stays selected', function () {
    [$thread, , $ids] = overlayThread(['bin/deploy.sh', 'bin/status.sh'], [null, 'ok'], 'running');

    $page = visit(route('threads.show', ['thread' => $thread, 'log' => $ids[0]]))
        ->assertVisible(OVERLAY)
        ->assertSee('Step 1 of 2')
        ->assertDontSee('deploy finished successfully');

    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 100, 'type' => 'entry', 'payload' => ['role' => 'tool_result', 'toolUseId' => 'toolu_0', 'isError' => false, 'text' => 'deploy finished successfully']]);

    for ($attempt = 0; $attempt < 40 && ! str_contains((string) $page->script('document.body.innerText'), 'deploy finished successfully'); $attempt++) {
        usleep(250_000);
    }

    $page->assertSee('deploy finished successfully')
        ->assertSee('Step 1 of 2')
        ->assertNoJavaScriptErrors();
});

it('does not read a finished run again when a step has no output', function () {
    [$thread, , $ids] = overlayThread(['bin/deploy.sh'], [null]);

    $page = visit(route('threads.show', ['thread' => $thread, 'log' => $ids[0]]))
        ->assertVisible(OVERLAY)
        ->assertSee('Step 1 of 1');
    $countRequests = fn () => $page->script('performance.getEntriesByType("resource").filter((entry) => entry.name.includes("/log")).length');
    $before = $countRequests();
    $page->wait(4);

    expect($before)->toBeGreaterThan(0)
        ->and($countRequests())->toBe($before);
});

it('opens the full log from the working row of a running turn', function () {
    [$thread] = overlayThread(['bin/deploy.sh', 'bin/status.sh'], [null, 'ok'], 'running');

    visit(route('threads.show', $thread))
        ->assertVisible('[data-testid="run-log-button-running"]')
        ->assertMissing('[data-testid="run-log-button"]')
        ->click('[data-testid="run-log-button-running"]')
        ->assertVisible(OVERLAY)
        ->assertSee('bin/deploy.sh')
        ->assertNoJavaScriptErrors();
});

it('keeps a deep link to an entry older than the first page open', function () {
    [$thread, , $ids] = overlayThread(array_map(fn (int $number): string => "echo step-{$number}", range(1, 120)));

    // The log pages in ascending order, 50 steps at a time, so step 60 is only on the second page.
    $page = visit(route('threads.show', ['thread' => $thread, 'log' => $ids[59]]))
        ->assertVisible(OVERLAY)
        ->assertSee('echo step-60')
        ->assertSee('Step 60 of')
        ->assertNoJavaScriptErrors();

    expect($page->script('performance.getEntriesByType("resource").filter((entry) => entry.name.includes("/log")).length'))->toBeGreaterThanOrEqual(2);
});

it('wraps a long command inside the detail pane instead of widening it', function () {
    [$thread, , $ids] = overlayThread([str_repeat('docker run --rm -v /workspace:/var/www ', 20)], ['']);

    $page = visit(route('threads.show', ['thread' => $thread, 'log' => $ids[0]]))->assertVisible(OVERLAY);

    $overflows = $page->script('(() => { const overlay = document.querySelector(\'[data-testid="transcript-overlay"]\'); return [...overlay.querySelectorAll("pre")].some(pre => pre.getBoundingClientRect().right > overlay.getBoundingClientRect().right); })()');

    expect($overflows)->toBeFalse();
});

it('shows full assistant messages, log details and the empty state', function () {
    $thread = Thread::factory()->create(['created_by_user_id' => auth()->id(), 'owner_user_id' => auth()->id()]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'repo' => $thread->repo, 'status' => TaskStatus::Success, 'pr_url' => null]);
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => 'completed', 'started_at' => now()->subHour()]);
    $fullText = "## For the reviewer\n\n" . str_repeat('The PR replaces the container stage. ', 20) . "\n\n## Verdict\n\n**Approve with suggestions**";
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 1, 'type' => 'turn', 'payload' => ['turnId' => $run->id, 'state' => 'running']]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 2, 'type' => 'entry', 'payload' => ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => $fullText]]]]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 3, 'type' => 'entry', 'payload' => ['role' => 'result', 'isError' => true, 'text' => 'Structured review missing required key: verdict']]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 4, 'type' => 'entry', 'payload' => ['role' => 'system', 'model' => null]]);
    $thread->forceFill(['event_sequence' => 4])->save();

    $page = visit(route('threads.show', $thread))
        ->click('[data-testid="run-log-button"]')
        ->assertSee('Step 1 of 3')
        ->assertSee('Approve with suggestions');

    $page->click('[data-testid="log-next"]')
        ->assertSee('Step 2 of 3')
        ->assertSee('Structured review missing required key: verdict');

    $page->click('[data-testid="log-next"]')
        ->assertSee('Step 3 of 3')
        ->assertSee('Session started')
        ->assertNoJavaScriptErrors();
});
