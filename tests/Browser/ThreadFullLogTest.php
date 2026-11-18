<?php

use App\Enums\TaskStatus;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\YakTask;

/**
 * A thread whose runs each have the given steps. A step is a Bash call with a result: [command, output, isError],
 * or [command, null] for a call that has no result yet. Step entries take the sequences after the run's turn event.
 *
 * @param  list<list<array{0: string, 1: ?string, 2?: bool}>>  $runs
 * @return array{0: Thread, 1: list<TaskRun>, 2: list<list<int>>}
 */
function fullLogThread(array $runs, string $status = 'success'): array
{
    $thread = Thread::factory()->create(['created_by_user_id' => auth()->id(), 'owner_user_id' => auth()->id()]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'repo' => $thread->repo, 'status' => TaskStatus::from($status), 'pr_url' => null, 'started_at' => now()]);
    $sequence = 1;
    $models = [];
    $ids = [];

    foreach ($runs as $runIndex => $steps) {
        $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => 'completed', 'started_at' => now()->subHours(3 - $runIndex), 'num_turns' => count($steps), 'total_ms' => 14 * 60_000]);
        ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => $sequence++, 'type' => 'turn', 'payload' => ['turnId' => $run->id, 'state' => 'running']]);
        $models[] = $run;
        $ids[$runIndex] = [];

        foreach ($steps as $stepIndex => $step) {
            $callId = "toolu_{$runIndex}_{$stepIndex}";
            $ids[$runIndex][] = $sequence;
            ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => $sequence++, 'type' => 'entry', 'payload' => ['role' => 'assistant', 'blocks' => [['type' => 'tool_use', 'id' => $callId, 'name' => 'Bash', 'input' => ['command' => $step[0]]]]]]);

            if ($step[1] !== null) {
                ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => $sequence++, 'type' => 'entry', 'payload' => ['role' => 'tool_result', 'toolUseId' => $callId, 'isError' => $step[2] ?? false, 'text' => $step[1]]]);
            }
        }
    }

    $thread->forceFill(['event_sequence' => $sequence - 1])->save();

    return [$thread, $models, $ids];
}

const FULL_LOG = '[data-testid="transcript-overlay"]:visible';

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('moves between the runs of a thread without closing the log and keeps the address in step', function () {
    [$thread, $runs, $ids] = fullLogThread([[['echo one', 'one'], ['echo two', 'two']], [['echo three', 'three']]]);

    $page = visit(route('threads.show', ['thread' => $thread, 'log' => $ids[0][1]]))
        ->assertVisible(FULL_LOG)
        ->assertSeeIn('[data-testid="transcript-run-line"]', 'Run 1 of 2')
        ->assertSeeIn('[data-testid="transcript-run-line"]', '2 steps')
        ->assertSeeIn('[data-testid="transcript-run-line"]', '14m')
        ->assertAttribute('[data-testid="run-previous"]', 'disabled', '')
        ->assertAttributeMissing('[data-testid="run-next"]', 'disabled')
        ->click('[data-testid="run-next"]')
        ->assertSeeIn('[data-testid="transcript-run-line"]', 'Run 2 of 2')
        ->assertSee('echo three')
        ->assertAttribute('[data-testid="run-next"]', 'disabled', '')
        ->assertVisible(FULL_LOG);

    expect($page->script('new URL(location.href).searchParams.get("log")'))->toBe((string) $ids[1][0]);

    $reloaded = visit(route('threads.show', ['thread' => $thread, 'log' => $ids[1][0]]))
        ->assertSeeIn('[data-testid="transcript-run-line"]', 'Run 2 of 2')
        ->assertSee('echo three');

    $reloaded->click('[data-testid="run-previous"]')
        ->assertSeeIn('[data-testid="transcript-run-line"]', 'Run 1 of 2')
        ->assertSee('echo one')
        ->assertAttribute('[data-testid="run-previous"]', 'disabled', '')
        ->assertNoJavaScriptErrors();
});

it('searches a run, including steps on a page that is not loaded yet, and restores the list when cleared', function () {
    $steps = array_map(fn (int $number): array => ["echo step-{$number}", (string) $number], range(1, 120));
    $steps[104] = ['echo late', 'needle in the late output'];
    [$thread, , $ids] = fullLogThread([$steps]);

    $page = visit(route('threads.show', ['thread' => $thread, 'log' => $ids[0][0]]))
        ->assertVisible(FULL_LOG)
        ->assertPresent('[data-testid="transcript-load-more"]')
        ->fill('[data-testid="transcript-search"]', 'needle')
        ->assertSeeIn('[data-testid="transcript-search-count"]', '1 step matches')
        ->assertSee('echo late')
        ->assertMissing('[data-testid="transcript-load-more"]')
        ->fill('[data-testid="transcript-search"]', 'no such text anywhere')
        ->assertPresent('[data-testid="transcript-no-match"]')
        ->assertSeeIn('[data-testid="transcript-no-match"]', 'No steps match')
        ->click('[data-testid="transcript-search-clear"]')
        ->assertPresent('[data-testid="transcript-load-more"]')
        ->assertMissing('[data-testid="transcript-search-count"]')
        ->assertSee('echo step-3');

    $page->assertNoJavaScriptErrors();
});

it('clears the search with escape and closes with the next escape, and returns focus to the button that opened it', function () {
    [$thread] = fullLogThread([[['echo one', 'one'], ['echo two', 'two']]]);

    $page = visit(route('threads.show', $thread))
        ->click('[data-testid="run-log-button"]')
        ->assertVisible(FULL_LOG)
        ->keys('[data-testid="transcript-overlay"]', ['/'])
        ->type('[data-testid="transcript-search"]', 'two')
        ->assertSeeIn('[data-testid="transcript-search-count"]', '1 step matches')
        ->keys('[data-testid="transcript-search"]', ['Escape'])
        ->assertVisible(FULL_LOG)
        ->assertValue('[data-testid="transcript-search"]', '')
        ->keys('[data-testid="transcript-search"]', ['Escape'])
        ->assertMissing(FULL_LOG);

    expect($page->script('document.activeElement?.dataset?.testid'))->toBe('run-log-button');
});

it('copies a link that opens the same run and step', function () {
    [$thread, , $ids] = fullLogThread([[['echo one', 'one'], ['echo two', 'two']]]);

    $page = visit(route('threads.show', ['thread' => $thread, 'log' => $ids[0][1]]))->assertVisible(FULL_LOG);
    $page->script('window.__copied = null; Object.defineProperty(navigator, "clipboard", { value: { writeText: (text) => { window.__copied = text; return Promise.resolve(); } }, configurable: true });');
    $page->click('[data-testid="copy-step-link"]')->assertSeeIn('[data-testid="copy-step-link"]', 'Link copied');

    $copied = $page->script('window.__copied');
    expect($copied)->toBe(route('threads.show', ['thread' => $thread, 'log' => $ids[0][1]]));

    visit($copied)->assertVisible(FULL_LOG)->assertSee('echo two')->assertSeeIn('[data-testid="transcript-step"]', 'Step 2');
});

it('shows a failed step, a step with no output yet, a very long output and an empty run', function () {
    [$thread, , $ids] = fullLogThread([[['vendor/bin/pest', "FAIL  tests/Unit/DatabaseTest\nTests: 1 failed", true], ['bin/deploy.sh', null], ['cat big.log', implode("\n", array_map(fn (int $line): string => "line {$line} of a long output", range(1, 600)))]]]);

    $page = visit(route('threads.show', ['thread' => $thread, 'log' => $ids[0][0]]))
        ->assertVisible(FULL_LOG)
        ->assertSeeIn('[data-testid="step-status"]', 'Failed')
        ->assertSee('Tests: 1 failed')
        ->click('[data-testid="log-next"]')
        ->assertSeeIn('[data-testid="step-status"]', 'No output yet')
        ->click('[data-testid="log-next"]')
        ->assertSeeIn('[data-testid="step-status"]', 'Done')
        ->assertSee('line 1 of a long output')
        ->assertVisible('[data-testid="log-prev"]');

    $inView = $page->script('(() => { const b = document.querySelector(\'[data-testid="log-prev"]\').getBoundingClientRect(); const o = document.querySelector(\'[data-testid="transcript-overlay"]\').getBoundingClientRect(); return b.bottom <= o.bottom && b.top >= o.top; })()');
    expect($inView)->toBeTrue();

    [$empty] = fullLogThread([[]]);
    visit(route('threads.show', $empty))->assertNoJavaScriptErrors();
});

it('disables Previous step and Next step until there is a step to go to', function () {
    [$thread] = fullLogThread([[['echo only', 'only']]]);

    visit(route('threads.show', $thread))
        ->click('[data-testid="run-log-button"]')
        ->assertSee('echo only')
        ->assertAttribute('[data-testid="log-prev"]', 'disabled', '')
        ->assertAttribute('[data-testid="log-next"]', 'disabled', '')
        ->assertNoJavaScriptErrors();
});

it('answers 404 for the page of a thread the viewer cannot see, so no log link opens', function () {
    $hidden = Thread::factory()->private()->create();

    $this->get(route('threads.show', ['thread' => $hidden, 'log' => 2]))->assertNotFound();
});

it('goes to the first result with Next step when a search has dropped the open step', function () {
    [$thread, , $ids] = fullLogThread([array_map(fn (int $number): array => ["echo {$number}", (string) $number], range(1, 6))]);

    visit(route('threads.show', ['thread' => $thread, 'log' => $ids[0][0]]))
        ->assertVisible(FULL_LOG)
        ->fill('[data-testid="transcript-search"]', 'echo 4')
        ->assertSeeIn('[data-testid="transcript-search-count"]', '1 step matches')
        ->click('[data-testid="log-next"]')
        ->assertSeeIn('[data-testid="transcript-step"]', 'Step 4')
        ->assertNoJavaScriptErrors();
});

it('shows shell commands with a prompt and announces the step to a screen reader', function () {
    [$thread, , $ids] = fullLogThread([[['echo one', 'one'], ['echo two', 'two']]]);

    visit(route('threads.show', ['thread' => $thread, 'log' => $ids[0][0]]))
        ->assertVisible(FULL_LOG)
        ->assertSee('$ echo one')
        ->assertSeeIn('[data-testid="transcript-step-announcement"]', 'Step 1, Run')
        ->click('[data-testid="log-next"]')
        ->assertSeeIn('[data-testid="transcript-step-announcement"]', 'Step 2, Run');
});

it('keeps Next step disabled until the log has loaded and then enables it', function () {
    [$thread] = fullLogThread([[['echo one', 'one'], ['echo two', 'two']]]);

    $page = visit(route('threads.show', $thread));
    $page->script('(() => { const original = window.fetch; window.fetch = (url, options) => String(url).includes("/log") ? new Promise((resolve) => setTimeout(() => resolve(original(url, options)), 1500)) : original(url, options); })();');
    $page->click('[data-testid="run-log-button"]')
        ->assertVisible(FULL_LOG)
        ->assertAttribute('[data-testid="log-next"]', 'disabled', '')
        ->assertAttribute('[data-testid="log-prev"]', 'disabled', '')
        ->assertSee('echo one')
        ->assertAttributeMissing('[data-testid="log-next"]', 'disabled')
        ->assertNoJavaScriptErrors();
});

it('says so when searches come too fast and recovers on Retry', function () {
    [$thread, , $ids] = fullLogThread([[['echo one', 'one'], ['echo two', 'two']]]);

    $page = visit(route('threads.show', ['thread' => $thread, 'log' => $ids[0][0]]))->assertVisible(FULL_LOG);
    $page->script('window.__limited = true; (() => { const original = window.fetch; window.fetch = (url, options) => window.__limited && String(url).includes("search=") ? Promise.resolve(new Response("{}", { status: 429, headers: { "Content-Type": "application/json" } })) : original(url, options); })();');
    $page->fill('[data-testid="transcript-search"]', 'two')
        ->assertSeeIn('[data-testid="transcript-error"]', 'Searching too fast, try again.');
    $page->script('window.__limited = false');
    $page->click('[data-testid="transcript-retry"]')
        ->assertSeeIn('[data-testid="transcript-search-count"]', '1 step matches')
        ->assertNoJavaScriptErrors();
});

it('opens the run named in the address on its first step', function () {
    [$thread, $runs] = fullLogThread([[['echo one', 'one']], [['echo three', 'three']]]);

    visit(route('threads.show', ['thread' => $thread, 'run' => $runs[1]->id]))
        ->assertVisible(FULL_LOG)
        ->assertSeeIn('[data-testid="transcript-run-line"]', 'Run 2 of 2')
        ->assertSee('echo three')
        ->assertNoJavaScriptErrors();
});

it('does not ask again for a searched log while the run is live', function () {
    [$thread, , $ids] = fullLogThread([[['echo one', 'one'], ['echo two', 'two']]], 'running');

    $page = visit(route('threads.show', ['thread' => $thread, 'log' => $ids[0][0]]))->assertVisible(FULL_LOG);
    $page->fill('[data-testid="transcript-search"]', 'two')->assertSeeIn('[data-testid="transcript-search-count"]', '1 step matches');
    $count = fn () => $page->script('performance.getEntriesByType("resource").filter((entry) => entry.name.includes("search=")).length');
    $before = $count();
    $page->wait(7);

    expect($before)->toBeGreaterThan(0)->and($count())->toBe($before);
});
