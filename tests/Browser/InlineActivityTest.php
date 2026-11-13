<?php

use App\Enums\TaskMode;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadEventPublisher;

require_once __DIR__ . '/Support/InlineRun.php';

function inlineRunningTask(): YakTask
{
    $task = YakTask::factory()->running()->create(['mode' => TaskMode::Setup, 'description' => 'Set up Geocodio/website for Yak', 'started_at' => now()->subMinutes(13)]);
    seedInlineRun($task, 'running');

    return $task;
}

test('a running task streams its steps inside Yak\'s reply and nothing else duplicates them', function () {
    $this->actingAs(User::factory()->create());
    $task = inlineRunningTask();

    visit(route('tasks.show', $task))
        ->assertVisible('[data-testid="yak-bubble"] [data-testid="activity-stream"]')
        ->assertVisible('[data-testid="step-current"]')
        ->assertSeeIn('[data-testid="step-current"]', 'git push origin yak/sandbox-setup')
        ->assertPresent('[data-testid="step-group"]')
        ->assertMissing('[data-testid="activity-log"]')
        ->assertMissing('[data-testid="live-line"]')
        ->assertPresent('[data-testid="progress-strip"]')
        ->assertDontSee('Working…')
        ->assertNoJavaScriptErrors();
});

test('earlier steps fold into one line per phase, the active group keeps the last three and the current step', function () {
    $this->actingAs(User::factory()->create());
    $task = inlineRunningTask();

    $page = visit(route('tasks.show', $task))
        ->assertSee('Ran 1 command, read 6 files, 1 thinking step')
        ->assertSee('1 failed, retried')
        ->assertSee('earlier steps');

    // The active group shows its last three finished rows plus the one running; the rest sit behind the fold.
    expect($page->script('[...document.querySelectorAll(\'[data-testid="activity-stream"] > div:last-of-type [data-testid="step-row"]\')].filter((row) => row.checkVisibility()).length'))->toBe(3);
    expect($page->script('document.querySelectorAll(\'[data-testid="step-current"]\').length'))->toBe(1);
});

test('a folded group opens and a row expands to its command and output, with a way into the full log', function () {
    $this->actingAs(User::factory()->create());
    $task = inlineRunningTask();

    $page = visit(route('tasks.show', $task))
        ->click('[data-testid="step-group"] > summary >> nth=0')
        ->assertSee('README.md')
        ->click('[data-testid="step-row"] > summary >> nth=0')
        ->assertVisible('[data-testid="step-detail"]')
        ->assertSeeIn('[data-testid="step-detail"]', 'Laravel app. Local development runs through Docker Compose.')
        ->assertSeeIn('[data-testid="step-detail"]', 'Open in full log')
        ->assertNoJavaScriptErrors();

    $page->click('[data-testid="step-detail"] button')
        ->assertVisible('[data-testid="transcript-overlay"]:visible')
        ->assertSee('Laravel app')
        ->assertNoJavaScriptErrors();
});

test('Full log opens the transcript overlay with the filters and search', function () {
    $this->actingAs(User::factory()->create());
    $task = inlineRunningTask();

    visit(route('tasks.show', $task))
        ->click('[data-testid="open-transcript"]')
        ->assertVisible('[data-testid="transcript-overlay"]:visible')
        ->assertPresent('[data-testid="transcript-search"]')
        ->assertSee('Milestones')
        ->assertNoJavaScriptErrors();
});

test('a finished task folds its run into one line with the totals and leads with the answer', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->success()->create([
        'mode' => TaskMode::Setup,
        'description' => 'Set up Geocodio/website for Yak',
        'started_at' => now()->subMinutes(13),
        'duration_ms' => 749_000,
        'branch_name' => 'yak/sandbox-setup',
        'pr_url' => 'https://github.com/Geocodio/website/pull/412',
        'result_summary' => "## Geocodio/website is set up\n\nThe app boots in the sandbox and the test suite passes.",
    ]);
    seedInlineRun($task);

    visit(route('tasks.show', $task))
        ->assertSeeIn('[data-testid="finished-summary"]', 'Worked for 12m 29s')
        ->assertSeeIn('[data-testid="finished-summary"]', 'Ran 14 commands, read 6 files, 3 thinking steps')
        ->assertSee('Geocodio/website is set up')
        ->assertSeeIn('[data-testid="run-milestones"]', 'Pushed yak/sandbox-setup')
        ->assertSeeIn('[data-testid="run-milestones"]', 'CI passing')
        ->assertSeeIn('[data-testid="run-milestones"]', 'Pull request #412')
        ->assertSee('Review PR #412')
        ->assertMissing('[data-testid="progress-strip"]')
        ->click('[data-testid="finished-summary"] > summary')
        ->assertPresent('[data-testid="finished-summary"] [data-testid="step-group"]')
        ->assertNoJavaScriptErrors();
});

test('a failed task shows its error once, in the conversation, with the failing command and a retry', function () {
    $this->actingAs(User::factory()->create());
    $error = 'npm ci cannot download the design tokens package because the registry answered 401.';
    $task = YakTask::factory()->failed()->create([
        'mode' => TaskMode::Setup,
        'description' => 'Set up Geocodio/website for Yak',
        'duration_ms' => 542_000,
        'error_log' => $error,
        'pr_url' => null,
        'result_summary' => null,
    ]);
    seedInlineRun($task, 'failed');

    $page = visit(route('tasks.show', $task))
        ->assertPresent('[data-testid="run-error"]')
        ->assertSeeIn('[data-testid="run-error"]', 'Setup could not finish')
        ->assertSeeIn('[data-testid="run-error"]', $error)
        ->assertSeeIn('[data-testid="run-error"] [data-testid="step-row"]', 'npm ci')
        ->assertSeeIn('[data-testid="run-error"]', 'exit 1')
        ->assertVisible('[data-testid="run-error"] [data-testid="retry-run"]')
        ->assertSeeIn('[data-testid="finished-summary"]', 'Stopped after 9m 02s')
        ->assertNoJavaScriptErrors();

    expect($page->script('document.body.innerText.split(' . json_encode($error) . ').length - 1'))->toBe(1);
    // No red banner above the thread: the only tinted failure surface is the card.
    expect($page->script('document.querySelectorAll(\'[data-testid="task-summary"] .bg-fail-soft\').length'))->toBe(0);
});

test('on a phone the tabs are Conversation and Details and the page never scrolls sideways', function () {
    $this->actingAs(User::factory()->create());
    $task = inlineRunningTask();

    $page = visit(route('tasks.show', $task))->on()->mobile()->resize(390, 844);

    $page->assertVisible('[data-testid="task-tab-conversation"]')
        ->assertVisible('[data-testid="task-tab-details"]')
        ->assertMissing('[data-testid="task-tab-activity"]')
        ->assertMissing('[data-testid="task-tab-overview"]')
        ->assertVisible('[data-testid="step-current"]')
        ->assertSeeIn('[data-testid="progress-strip"] p', 'Working')
        ->assertNoJavaScriptErrors();

    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();

    $page->click('[data-testid="step-group"] > summary >> nth=0')
        ->click('[data-testid="step-row"] > summary >> nth=0');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();

    $page->click('[data-testid="task-tab-details"]')
        ->assertMissing('[data-testid="step-current"]:visible')
        ->assertQueryStringHas('tab', 'details');
});

test('a thread draws its tool calls with the same shared step rows', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'title' => 'Fix the login redirect']);
    $publisher = app(ThreadEventPublisher::class);
    $publisher->append($thread->id, 'entry', ['role' => 'assistant', 'messageId' => 'm1', 'blocks' => [
        ['type' => 'tool_use', 'id' => 't1', 'name' => 'Read', 'input' => ['file_path' => 'app/Http/Controllers/LoginController.php']],
        ['type' => 'tool_use', 'id' => 't2', 'name' => 'Bash', 'input' => ['command' => 'php artisan test --compact']],
        ['type' => 'text', 'text' => 'Done reading'],
    ]]);
    $publisher->append($thread->id, 'entry', ['role' => 'tool_result', 'toolUseId' => 't1', 'isError' => false, 'text' => '<?php class LoginController {}']);
    $publisher->append($thread->id, 'entry', ['role' => 'tool_result', 'toolUseId' => 't2', 'isError' => true, 'text' => 'Tests: 1 failed']);
    $this->actingAs($user);

    visit("/threads/{$thread->public_id}")->wait(1)
        ->assertVisible('[data-testid="thread-tool-activity"] [data-testid="step-group"]')
        ->assertSeeIn('[data-testid="thread-tool-activity"]', 'Ran 1 command, read 1 file')
        ->assertSeeIn('[data-testid="thread-tool-activity"]', '1 failed')
        ->click('[data-testid="thread-tool-activity"] [data-testid="step-group"] > summary')
        ->click('[data-testid="thread-tool-activity"] [data-testid="step-row"] > summary >> nth=0')
        ->assertSeeIn('[data-testid="step-detail"]', 'class LoginController')
        ->assertNoJavaScriptErrors();
});
