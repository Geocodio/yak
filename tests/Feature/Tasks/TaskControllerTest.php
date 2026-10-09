<?php

use App\Enums\SteeringMode;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Models\Artifact;
use App\Models\BranchDeployment;
use App\Models\PendingSteeringMessage;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\Repository;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\User;
use App\Models\YakTask;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('it requires authentication', function () {
    auth()->logout();
    $task = YakTask::factory()->create();

    $this->get(route('tasks.show', $task))->assertRedirect(route('login'));
});

test('it renders the task detail page with the task fields', function () {
    $task = YakTask::factory()->create([
        'description' => 'Fix the duplicate entry crash',
        'status' => TaskStatus::Success,
        'repo' => 'my-repo',
        'external_id' => 'SLACK-42',
        'source' => 'slack',
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
    ]);

    $this->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tasks/Show')
            ->where('task.id', $task->id)
            ->where('task.status', 'success')
            ->where('task.repo', 'my-repo')
            ->where('task.externalId', 'SLACK-42')
            ->has('task.headline')
            ->has('thread')
            ->has('activity.rows')
            ->has('progress.steps')
            ->has('actions')
            ->etc());
});

test('a Sentry task names the issue that triggered it', function () {
    $task = YakTask::factory()->create([
        'source' => 'sentry',
        'external_id' => '7547344735',
        'external_url' => 'https://sentry.io/organizations/acme/issues/7547344735/',
        'context' => json_encode(['sentry_issue_id' => '7547344735', 'error' => 'TypeError: Cannot assign string']),
    ]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('task.sourceUrl', 'https://sentry.io/organizations/acme/issues/7547344735/')
            ->where('task.trigger', [
                'label' => 'Sentry issue 7547344735',
                'url' => 'https://sentry.io/organizations/acme/issues/7547344735/',
                'lines' => [['text' => 'TypeError: Cannot assign string', 'url' => null]],
            ])
            ->etc());
});

test('a flaky-test task names the failing tests and links the CI build', function () {
    $task = YakTask::factory()->create([
        'source' => 'flaky-test',
        'external_url' => 'https://github.com/acme/app/actions/runs/1',
        'context' => json_encode(['tests' => [
            ['test_name' => 'Tests\\Feature\\FooTest > it works', 'build_urls' => ['https://github.com/acme/app/actions/runs/1/job/7']],
            ['test_name' => 'Tests\\Feature\\BarTest > it works'],
        ]]),
    ]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('task.sourceLabel', 'Flaky test')
            ->where('task.sourceUrl', 'https://github.com/acme/app/actions/runs/1')
            ->where('task.trigger', [
                'label' => 'CI build',
                'url' => 'https://github.com/acme/app/actions/runs/1',
                'lines' => [
                    ['text' => 'Tests\\Feature\\FooTest > it works', 'url' => 'https://github.com/acme/app/actions/runs/1/job/7'],
                    ['text' => 'Tests\\Feature\\BarTest > it works', 'url' => null],
                ],
            ])
            ->etc());
});

test('a Slack task has no trigger line', function () {
    $task = YakTask::factory()->create(['source' => 'slack']);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page->where('task.trigger', null)->etc());
});

test('a follow-up task url redirects to the root task', function () {
    $root = YakTask::factory()->create();
    $child = YakTask::factory()->create(['parent_task_id' => $root->id]);

    $this->get(route('tasks.show', $child))
        ->assertRedirect(route('tasks.show', $root) . '#turn-' . $child->id);
});

test('thread includes user and yak entries', function () {
    $task = YakTask::factory()->create([
        'description' => 'Fix the duplicate entry crash',
        'result_summary' => 'Guarded the insert with a validation rule.',
        'status' => TaskStatus::Success,
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
    ]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->has('thread', 2)
            ->where('thread.0.kind', 'user')
            ->where('thread.1.kind', 'yak')
            ->where('thread.1.bodyHtml', fn (string $html) => str_contains($html, 'Guarded the insert')));
});

test('clarification entry carries its options', function () {
    $task = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingClarification,
        'clarification_options' => ['Convert in place', 'Keep both'],
        'clarification_expires_at' => now()->addHours(3),
    ]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->has('thread.1', fn (Assert $entry) => $entry
                ->where('kind', 'clarification')
                ->where('options', ['Convert in place', 'Keep both'])
                ->has('expiresIn')
                ->etc()));
});

test('a follow-up waiting on answers shows when its questions expire', function () {
    $root = YakTask::factory()->create(['status' => TaskStatus::Success, 'created_at' => now()->subHour()]);
    YakTask::factory()->withClarificationQuestions()->create([
        'parent_task_id' => $root->id,
        'clarification_expires_at' => now()->addDays(3),
    ]);

    $this->get(route('tasks.show', $root))
        ->assertInertia(fn (Assert $page) => $page
            ->where('thread', fn ($thread) => collect($thread)->contains(
                fn (array $entry) => $entry['kind'] === 'clarification' && str_contains($entry['meta'], 'expires') && $entry['expiresIn'] !== null,
            )));
});

test('markdown in the thread strips raw html', function () {
    $task = YakTask::factory()->create([
        'description' => "Before the script.\n\n<script>alert(1)</script>\n\nAfter the script.",
        'status' => TaskStatus::Success,
    ]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('thread.0.bodyHtml', fn (string $html) => ! str_contains($html, '<script>alert(1)</script>')
                && str_contains($html, 'Before the script.')
                && str_contains($html, 'After the script.')));
});

test('review context turn is built from context json', function () {
    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'context' => json_encode([
            'pr_number' => 42,
            'author' => 'octocat',
            'title' => 'Fix the flaky test',
            'body' => 'This stabilizes the retry logic.',
        ]),
    ]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('thread.0.kind', 'review-context')
            ->where('thread.0.who', 'Fix the flaky test')
            ->where('thread.0.meta', fn (string $meta) => str_contains($meta, 'PR #42') && str_contains($meta, 'octocat'))
            ->where('thread.0.bodyHtml', fn (string $html) => str_contains($html, 'stabilizes the retry logic')));
});

test('composer state is steering for a running task', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page->where('composer.state', 'steering'));
});

test('composer lists the messages waiting for a busy task, steered ones first', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running]);
    PendingSteeringMessage::queueFor($task, 'first queued', 'dashboard', authorName: 'Ada');
    PendingSteeringMessage::queueFor($task, 'first steered', 'slack', mode: SteeringMode::Steer);
    PendingSteeringMessage::queueFor($task, 'second queued', 'dashboard');
    PendingSteeringMessage::queueFor($task, 'second steered', 'dashboard', mode: SteeringMode::Steer);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->has('composer.queued', 4)
            ->where('composer.queued.0.text', 'first steered')
            ->where('composer.queued.0.mode', 'steer')
            ->where('composer.queued.0.source', 'slack')
            ->where('composer.queued.1.text', 'second steered')
            ->where('composer.queued.2.text', 'first queued')
            ->where('composer.queued.2.mode', 'queue')
            ->where('composer.queued.2.authorName', 'Ada')
            ->where('composer.queued.3.text', 'second queued'));
});

test('composer marks a waiting GitHub review as one that cannot be steered', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running]);
    PendingSteeringMessage::queueFor($task, 'from the dashboard', 'dashboard');
    PendingSteeringMessage::queueFor($task, 'please rename', 'github_review', reviewerLogin: 'alice');

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('composer.queued.0.canSteer', true)
            ->where('composer.queued.1.canSteer', false));
});

test('composer state is clarification while awaiting clarification', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::AwaitingClarification]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page->where('composer.state', 'clarification'));
});

test('shows the questions form state with the pending questions', function () {
    $task = YakTask::factory()->withClarificationQuestions()->create();

    $this->get(route('tasks.show', $task))->assertInertia(fn (Assert $page) => $page
        ->where('composer.state', 'questions')
        ->where('questions.0.id', 'scope')
        ->where('questions.0.options.1.label', 'Large')
        ->where('questions.1.multiSelect', false));
});

test('shows answered rounds in the thread, with skipped questions', function () {
    $task = YakTask::factory()->withClarificationQuestions()->create(['status' => TaskStatus::Running]);
    $task->recordClarificationAnswers(['scope' => ['choices' => [], 'other' => 'Medium']], null, 'Michele');

    $this->get(route('tasks.show', $task))->assertInertia(fn (Assert $page) => $page
        ->where('questions', null)
        ->where('thread', fn ($thread) => collect($thread)->contains(fn ($entry) => $entry['kind'] === 'clarification-answers'
            && $entry['answers'][0] === ['header' => 'Scope', 'answer' => null, 'other' => 'Medium', 'skipped' => false]
            && $entry['answers'][1]['skipped'] === true)));
});

test('composer state is follow_up for a success task with an open pr', function () {
    $task = YakTask::factory()->success()->create();

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page->where('composer.state', 'follow_up'));
});

test('a review task reads its pr number from the pr url', function () {
    $task = YakTask::factory()->success()->create([
        'mode' => TaskMode::Review,
        'pr_url' => 'https://github.com/Geocodio/geocodio/pull/2846',
        'pr_number' => null,
    ]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('task.pr.number', 2846)
            ->where('composer.state', 'follow_up')
            ->where('composer.placeholder', 'Reply to Yak. It will push changes to PR #2846…'));
});

test('the follow-up placeholder names this PR when no number is known', function () {
    $task = YakTask::factory()->success()->create(['pr_url' => 'https://example.com/change', 'pr_number' => null]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page->where('composer.placeholder', 'Reply to Yak. It will push changes to this PR…'));
});

test('composer state is disabled_failed for a failed task', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Failed]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page->where('composer.state', 'disabled_failed'));
});

test('composer state is disabled_closed for a success task with no pr', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'pr_url' => null, 'source' => 'slack']);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('composer.state', 'disabled_closed')
            ->where('composer.placeholder', 'This conversation is closed — mention Yak again to start a new task.')
            ->where('composer.note', null));
});

test('transcriptEntry is omitted from a normal load and present with a log query param', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()]);
    $log = TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'attempt_number' => 1,
        'message' => 'Ran a command',
        'metadata' => ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => 'ls -la'], 'output' => 'total 0'],
    ]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page->missing('transcriptEntry')->missing('transcript'));

    $this->get(route('tasks.show', [$task, 'log' => $log->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('transcriptEntry.id', $log->id)
            ->where('transcriptEntry.input', 'ls -la')
            ->where('transcriptEntry.output', 'total 0')
            ->where('transcriptLogId', $log->id));
});

test('transcriptEntry resolves a log from another run in the same conversation', function () {
    $root = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()]);
    $followUp = YakTask::factory()->create(['parent_task_id' => $root->id, 'status' => TaskStatus::Success, 'started_at' => now()]);
    $log = TaskLog::factory()->create(['yak_task_id' => $followUp->id, 'attempt_number' => 1, 'message' => 'In the follow-up']);

    $this->get(route('tasks.show', [$root, 'log' => $log->id]))
        ->assertInertia(fn (Assert $page) => $page->where('transcriptEntry.text', 'In the follow-up'));
});

test('transcriptEntry can be requested as a partial reload without a log in the url', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()]);
    $log = TaskLog::factory()->create(['yak_task_id' => $task->id, 'attempt_number' => 1, 'message' => 'Fetched on demand']);

    // A partial reload's response is bare JSON, not the full page view, so
    // it is asserted with assertJsonPath rather than assertInertia (which
    // requires the `page` view data a full-page visit renders).
    $this->get(route('tasks.show', [$task, 'log' => $log->id]), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => inertiaVersion($task),
        'X-Inertia-Partial-Component' => 'Tasks/Show',
        'X-Inertia-Partial-Data' => 'transcriptEntry',
    ])->assertJsonPath('props.transcriptEntry.text', 'Fetched on demand')
        ->assertJsonMissingPath('props.thread');
});

test('transcriptEntry renders the full assistant message as markdown', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()]);
    $fullText = "## For the reviewer\n\n" . str_repeat('Long review prose. ', 60) . "\n\n## Verdict\n**Approve**";
    $log = TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'attempt_number' => 1,
        'message' => mb_substr($fullText, 0, 500) . '…',
        'metadata' => ['type' => 'assistant', 'text' => $fullText],
    ]);

    $this->get(route('tasks.show', [$task, 'log' => $log->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('transcriptEntry.kind', 'assistant')
            ->where('transcriptEntry.html', fn (string $html) => str_contains($html, '<h2>Verdict</h2>') && str_contains($html, '<strong>Approve</strong>')));
});

test('transcriptEntry lists a plain log entry\'s metadata as details', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Failed, 'started_at' => now()]);
    $withMetadata = TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'attempt_number' => 1,
        'level' => 'error',
        'message' => 'Failed to parse agent review output',
        'metadata' => ['error' => 'Structured review missing required key: verdict', 'raw_output' => '## For the reviewer'],
    ]);
    $bare = TaskLog::factory()->create(['yak_task_id' => $task->id, 'attempt_number' => 1, 'message' => 'Sandbox created', 'metadata' => null]);

    $this->get(route('tasks.show', [$task, 'log' => $withMetadata->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('transcriptEntry.details', [
                ['label' => 'Error', 'value' => 'Structured review missing required key: verdict', 'error' => true],
                ['label' => 'Raw output', 'value' => '## For the reviewer', 'error' => false],
            ]));

    $this->get(route('tasks.show', [$task, 'log' => $bare->id]))
        ->assertInertia(fn (Assert $page) => $page->where('transcriptEntry.details', []));
});

test('attempt query param selects the requested attempt', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now(), 'attempts' => 2]);
    TaskLog::factory()->create(['yak_task_id' => $task->id, 'attempt_number' => 1, 'message' => 'attempt one log']);
    TaskLog::factory()->create(['yak_task_id' => $task->id, 'attempt_number' => 2, 'message' => 'attempt two log']);

    $this->get(route('tasks.show', [$task, 'attempt' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('task.attempt', 1)
            ->has('activity.rows', 1)
            ->where('activity.rows.0.text', 'attempt one log'));

    $this->get(route('tasks.show', [$task, 'attempt' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('task.attempt', 2)
            ->has('activity.rows', 1)
            ->where('activity.rows.0.text', 'attempt two log'));
});

test('activity rows carry no server-side group; grouping is a client concern', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()]);
    TaskLog::factory()->create(['yak_task_id' => $task->id, 'attempt_number' => 1, 'message' => 'thinking', 'level' => 'info', 'metadata' => ['type' => 'assistant']]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->has('activity.rows', 1)
            ->where('activity.rows.0.kind', 'assistant')
            ->where('activity.rows.0.milestone', false)
            ->missing('activity.rows.0.group'));
});

/**
 * The build's actual Inertia asset version, needed to make a partial
 * reload request -- an empty or stale version gets a 409 conflict instead
 * of a page response.
 */
function inertiaVersion(YakTask $task): string
{
    return (string) test()->get(route('tasks.show', $task), ['X-Inertia' => 'true'])->headers->get('X-Inertia-Version');
}

function seedLogs(YakTask $task, int $count, int $attempt = 1): void
{
    $rows = [];
    foreach (range(1, $count) as $index) {
        $rows[] = [
            'yak_task_id' => $task->id,
            'attempt_number' => $attempt,
            'level' => 'info',
            'message' => "Step {$index}",
            'metadata' => json_encode(['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => "echo {$index}"], 'output' => (string) $index]),
            'created_at' => now()->subSeconds($count - $index),
        ];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        TaskLog::insert($chunk);
    }
}

test('activity sends the newest 200 rows oldest first, with a cursor and a summary', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()]);
    seedLogs($task, 250);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->has('activity.rows', 200)
            ->where('activity.rows.0.text', 'Step 51')
            ->where('activity.rows.199.text', 'Step 250')
            ->where('activity.hasOlder', true)
            ->where('activity.oldestId', fn ($id) => TaskLog::find($id)?->message === 'Step 51')
            ->where('activitySummary.entries', 250)
            ->where('activitySummary.latestId', fn ($id) => TaskLog::find($id)?->message === 'Step 250')
            ->has('activitySummary.duration')
            ->missing('activityOlder')
            ->missing('activityTail'));
});

test('activity has no older rows when the run fits the window', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()]);
    seedLogs($task, 3);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page->has('activity.rows', 3)->where('activity.hasOlder', false));
});

test('activityOlder returns the rows before a cursor as a partial reload', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()]);
    seedLogs($task, 250);
    $cursor = TaskLog::where('yak_task_id', $task->id)->where('message', 'Step 51')->value('id');

    $this->get(route('tasks.show', [$task, 'before' => $cursor]), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => inertiaVersion($task),
        'X-Inertia-Partial-Component' => 'Tasks/Show',
        'X-Inertia-Partial-Data' => 'activityOlder',
    ])->assertJsonCount(50, 'props.activityOlder')
        ->assertJsonPath('props.activityOlder.0.text', 'Step 1')
        ->assertJsonPath('props.activityOlder.49.text', 'Step 50')
        ->assertJsonMissingPath('props.activity');
});

test('activityTail returns only rows after a cursor, capped at the window, and the next cursor drains the rest', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);
    seedLogs($task, 260);
    $cursor = TaskLog::where('yak_task_id', $task->id)->where('message', 'Step 10')->value('id');
    $headers = [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => inertiaVersion($task),
        'X-Inertia-Partial-Component' => 'Tasks/Show',
        'X-Inertia-Partial-Data' => 'activityTail,activitySummary',
    ];

    $this->get(route('tasks.show', [$task, 'after' => $cursor]), $headers)
        ->assertJsonCount(200, 'props.activityTail')
        ->assertJsonPath('props.activityTail.0.text', 'Step 11')
        ->assertJsonPath('props.activityTail.199.text', 'Step 210')
        ->assertJsonPath('props.activitySummary.entries', 260);

    $nextCursor = TaskLog::where('yak_task_id', $task->id)->where('message', 'Step 210')->value('id');
    $this->get(route('tasks.show', [$task, 'after' => $nextCursor]), $headers)
        ->assertJsonCount(50, 'props.activityTail')
        ->assertJsonPath('props.activityTail.49.text', 'Step 260');
});

test('activityTail with after=0 returns the first rows of a run whose window started empty', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);
    seedLogs($task, 5);

    $this->get(route('tasks.show', [$task, 'after' => 0]), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => inertiaVersion($task),
        'X-Inertia-Partial-Component' => 'Tasks/Show',
        'X-Inertia-Partial-Data' => 'activityTail',
    ])->assertJsonCount(5, 'props.activityTail')
        ->assertJsonPath('props.activityTail.0.text', 'Step 1');
});

test('activity rows on an active run carry an absolute time and an ISO createdAt', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);
    seedLogs($task, 1);
    $log = TaskLog::where('yak_task_id', $task->id)->firstOrFail();

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('activity.rows.0.at', $log->created_at->format('g:i:s A'))
            ->where('activity.rows.0.createdAt', $log->created_at->toIso8601String())
            ->where('task.runId', $task->id));
});

test('activity cursors respect the selected attempt', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now(), 'attempts' => 2]);
    seedLogs($task, 5, attempt: 1);
    seedLogs($task, 3, attempt: 2);
    $cursor = TaskLog::where('yak_task_id', $task->id)->where('attempt_number', 2)->orderBy('id')->value('id');

    $this->get(route('tasks.show', [$task, 'attempt' => 2, 'after' => $cursor]), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => inertiaVersion($task),
        'X-Inertia-Partial-Component' => 'Tasks/Show',
        'X-Inertia-Partial-Data' => 'activityTail',
    ])->assertJsonCount(2, 'props.activityTail');
});

test('actions reflect what the task can do right now', function () {
    $failed = YakTask::factory()->create(['status' => TaskStatus::Failed]);
    $this->get(route('tasks.show', $failed))
        ->assertInertia(fn (Assert $page) => $page
            ->where('actions.canRetry', true)
            ->where('actions.canCancel', false));

    $running = YakTask::factory()->create(['status' => TaskStatus::Running]);
    $this->get(route('tasks.show', $running))
        ->assertInertia(fn (Assert $page) => $page
            ->where('actions.canRetry', false)
            ->where('actions.canCancel', true));
});

test('reroute targets exclude the current repo and hidden for setup and review modes', function () {
    Repository::factory()->create(['slug' => 'web', 'is_active' => true]);
    Repository::factory()->create(['slug' => 'api', 'is_active' => true]);

    $task = YakTask::factory()->create(['mode' => TaskMode::Fix, 'repo' => 'web', 'pr_url' => null]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('actions.canReroute', true)
            ->where('actions.rerouteTargets', ['api']));

    $review = YakTask::factory()->create(['mode' => TaskMode::Review, 'repo' => 'web']);
    $this->get(route('tasks.show', $review))
        ->assertInertia(fn (Assert $page) => $page->where('actions.canReroute', false));
});

test('findings are present for a review task with a pr review', function () {
    $task = YakTask::factory()->create(['mode' => TaskMode::Review]);
    $review = PrReview::factory()->for($task, 'task')->create(['verdict' => 'Approved', 'summary' => 'Looks good.']);
    PrReviewComment::factory()->for($review, 'review')->create([
        'severity' => 'must_fix',
        'file_path' => 'app/Foo.php',
        'line_number' => 12,
        'category' => 'bug',
        'body' => 'This will throw.',
    ]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('findings.verdict', 'Approved')
            ->where('findings.counts.mustFix', 1)
            ->has('findings.comments', 1, fn (Assert $comment) => $comment
                ->where('severity', 'must_fix')
                ->where('path', 'app/Foo.php')
                ->where('line', 12)
                ->has('bodyHtml')
                ->etc()));
});

test('findings are null for a task with no review', function () {
    $task = YakTask::factory()->create(['mode' => TaskMode::Review]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page->where('findings', null));
});

test('walkthrough status reflects the render pipeline', function () {
    $none = YakTask::factory()->create();
    $this->get(route('tasks.show', $none))
        ->assertInertia(fn (Assert $page) => $page->where('walkthrough.status', 'none'));

    $ready = YakTask::factory()->create();
    Artifact::factory()->for($ready, 'task')->videoCut()->create();
    $this->get(route('tasks.show', $ready))
        ->assertInertia(fn (Assert $page) => $page->where('walkthrough.status', 'ready')->has('walkthrough.videoUrl'));
});

test('deployment is present for a task branch with an active deployment', function () {
    $repo = Repository::factory()->create(['slug' => 'acme/app']);
    BranchDeployment::factory()->running()->create([
        'repository_id' => $repo->id,
        'branch_name' => 'feat/foo',
        'hostname' => 'acme-app-feat-foo.yak.example.com',
    ]);
    $task = YakTask::factory()->create(['repo' => 'acme/app', 'branch_name' => 'feat/foo']);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('deployment.hostname', 'acme-app-feat-foo.yak.example.com')
            ->where('deployment.url', 'https://acme-app-feat-foo.yak.example.com'));
});

test('deployment is null when the task has no branch', function () {
    $task = YakTask::factory()->create(['branch_name' => null]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page->where('deployment', null));
});

test('poll interval is fast while the task is active or pending and slow once it is done', function () {
    $pending = YakTask::factory()->create(['status' => TaskStatus::Pending]);
    $this->get(route('tasks.show', $pending))
        ->assertInertia(fn (Assert $page) => $page->where('pollInterval', 5000));

    $running = YakTask::factory()->create(['status' => TaskStatus::Running]);
    $this->get(route('tasks.show', $running))
        ->assertInertia(fn (Assert $page) => $page->where('pollInterval', 5000));

    $success = YakTask::factory()->create(['status' => TaskStatus::Success]);
    $this->get(route('tasks.show', $success))
        ->assertInertia(fn (Assert $page) => $page->where('pollInterval', 15000));
});

test('activity rows flag milestones for the three isMilestone cases', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()]);

    // Case 1: neither tool_use nor assistant (e.g. a plain source line, or no metadata) is always a milestone.
    $plain = TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'attempt_number' => 1,
        'message' => 'Task created from Slack message',
        'metadata' => ['source' => 'slack'],
    ]);

    // Case 2: tool_use / assistant at info level is not a milestone.
    $toolUse = TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'attempt_number' => 1,
        'message' => 'Ran grep',
        'level' => 'info',
        'metadata' => ['type' => 'tool_use', 'tool' => 'grep'],
    ]);
    $assistant = TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'attempt_number' => 1,
        'message' => 'Thinking',
        'level' => 'info',
        'metadata' => ['type' => 'assistant'],
    ]);

    // Case 3: error/warning level is a milestone regardless of type.
    $erroredToolUse = TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'attempt_number' => 1,
        'message' => 'Command failed',
        'level' => 'error',
        'metadata' => ['type' => 'tool_use', 'tool' => 'bash'],
    ]);
    $warnedAssistant = TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'attempt_number' => 1,
        'message' => 'Careful here',
        'level' => 'warning',
        'metadata' => ['type' => 'assistant'],
    ]);

    $milestonesById = collect(
        $this->get(route('tasks.show', $task))
            ->viewData('page')['props']['activity']['rows']
    )->keyBy('id')->map(fn (array $row) => $row['milestone']);

    expect($milestonesById[$plain->id])->toBeTrue()
        ->and($milestonesById[$toolUse->id])->toBeFalse()
        ->and($milestonesById[$assistant->id])->toBeFalse()
        ->and($milestonesById[$erroredToolUse->id])->toBeTrue()
        ->and($milestonesById[$warnedAssistant->id])->toBeTrue();
});

test('debug carries the error log for a failed task', function () {
    $task = YakTask::factory()->failed()->create(['error_log' => 'Fatal error: something went wrong']);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page->where('debug.Error Log', 'Fatal error: something went wrong'));
});

test('nextSteps copy for running research tasks mentions gathering findings, not making changes', function () {
    $task = YakTask::factory()->running()->create(['mode' => TaskMode::Research]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('task.nextSteps', fn (string $text) => str_contains($text, 'gathering findings') && ! str_contains($text, 'making changes')));
});

test('nextSteps copy for running fix tasks mentions making changes, not gathering findings', function () {
    $task = YakTask::factory()->running()->create(['mode' => TaskMode::Fix]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('task.nextSteps', fn (string $text) => str_contains($text, 'making changes') && ! str_contains($text, 'gathering findings')));
});

test('nextSteps copy for a failed task points at Retry', function () {
    $task = YakTask::factory()->failed()->create(['mode' => TaskMode::Fix]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('task.nextSteps', fn (string $text) => str_contains($text, 'Click Retry above')));
});

test('nextSteps copy for a failed review task points at Re-run review, not Retry', function () {
    $task = YakTask::factory()->failed()->create(['mode' => TaskMode::Review]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('task.nextSteps', fn (string $text) => str_contains($text, 'Click Re-run review above') && ! str_contains($text, 'Click Retry above')));
});

test('nextSteps is null while awaiting clarification, which has its own call-to-action', function () {
    $task = YakTask::factory()->awaitingClarification()->create(['clarification_options' => ['a', 'b']]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page->where('task.nextSteps', null));
});

test('researchArtifactUrl is present once a research task has its artifact', function () {
    $task = YakTask::factory()->success()->create(['mode' => TaskMode::Research]);
    $artifact = Artifact::factory()->research()->create(['yak_task_id' => $task->id]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('task.researchArtifactUrl', route('artifacts.viewer', ['task' => $task->id, 'filename' => $artifact->filename])));
});

test('researchArtifactUrl is null for a research task with no artifact yet', function () {
    $task = YakTask::factory()->running()->create(['mode' => TaskMode::Research]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page->where('task.researchArtifactUrl', null));
});

test('researchArtifactUrl is null for non-research tasks', function () {
    $task = YakTask::factory()->success()->create(['mode' => TaskMode::Fix]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page->where('task.researchArtifactUrl', null));
});

test('composer note points slack replies at the slack thread', function () {
    config()->set('yak.channels.slack.workspace_url', 'https://acme.slack.com');

    $task = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingClarification,
        'source' => 'slack',
        'slack_channel' => 'C1234567',
        'slack_thread_ts' => '1700000000.123456',
        'clarification_options' => ['option a', 'option b'],
        'clarification_expires_at' => now()->addDays(1),
    ]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('composer.note', 'Replies here and in the Slack thread land in the same conversation.'));
});

test('composer note points linear replies at the linear issue', function () {
    $task = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingClarification,
        'source' => 'linear',
        'external_url' => 'https://linear.app/acme/issue/ACM-42/fix-the-bug',
        'clarification_options' => ['option a', 'option b'],
        'clarification_expires_at' => now()->addDays(1),
    ]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('composer.note', 'Replies here and in the Linear thread land in the same conversation.'));
});

test('composer has no cross-channel note for unknown sources', function () {
    $task = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingClarification,
        'source' => 'system',
        'clarification_options' => ['option a', 'option b'],
        'clarification_expires_at' => now()->addDays(1),
    ]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('composer.note', fn (?string $note) => $note === null || ! str_contains($note, 'land in the same conversation')));
});

test('canReroute is false for Setup mode', function () {
    $task = YakTask::factory()->create(['mode' => TaskMode::Setup]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page->where('actions.canReroute', false));
});

test('rerouteTargets excludes the current repo and inactive repos', function () {
    $current = Repository::factory()->create(['slug' => 'org/current', 'is_active' => true]);
    Repository::factory()->create(['slug' => 'org/other', 'is_active' => true]);
    Repository::factory()->inactive()->create(['slug' => 'org/inactive']);

    $task = YakTask::factory()->create(['repo' => $current->slug, 'mode' => TaskMode::Fix, 'pr_url' => null]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page->where('actions.rerouteTargets', ['org/other']));
});

test('canRerunReview stays true and canRetry stays false for a finished review task', function () {
    // A Review-mode task's contextual action is `rerun_review` for any
    // status (see the deleted TaskDetail::contextualAction()) -- canRetry
    // is a plain status check with no mode gating, so it must independently
    // stay false once the review has succeeded.
    $task = YakTask::factory()->create(['mode' => TaskMode::Review, 'status' => TaskStatus::Success]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('actions.canRerunReview', true)
            ->where('actions.canRetry', false));
});

test('canRequestReview is true for a task with a PR on a repo with PR review enabled', function () {
    $repository = Repository::factory()->create(['slug' => 'org/reviewed', 'is_active' => true, 'pr_review_enabled' => true]);
    $task = YakTask::factory()->create(['repo' => $repository->slug, 'mode' => TaskMode::Fix, 'pr_url' => 'https://github.com/org/reviewed/pull/1']);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page->where('actions.canRequestReview', true));
});

test('canRequestReview is false without a PR, on review tasks, or when PR review is disabled', function () {
    $enabled = Repository::factory()->create(['slug' => 'org/enabled', 'is_active' => true, 'pr_review_enabled' => true]);
    $disabled = Repository::factory()->create(['slug' => 'org/disabled', 'is_active' => true, 'pr_review_enabled' => false]);

    $withoutPr = YakTask::factory()->create(['repo' => $enabled->slug, 'mode' => TaskMode::Fix, 'pr_url' => null]);
    $review = YakTask::factory()->create(['repo' => $enabled->slug, 'mode' => TaskMode::Review, 'pr_url' => 'https://github.com/org/enabled/pull/2']);
    $reviewDisabled = YakTask::factory()->create(['repo' => $disabled->slug, 'mode' => TaskMode::Fix, 'pr_url' => 'https://github.com/org/disabled/pull/3']);

    foreach ([$withoutPr, $review, $reviewDisabled] as $task) {
        $this->get(route('tasks.show', $task))
            ->assertInertia(fn (Assert $page) => $page->where('actions.canRequestReview', false));
    }
});

test('show exposes who started the task and who is responsible', function () {
    $task = YakTask::factory()->success()->create([
        'author_name' => 'Jane Doe',
        'responsible_name' => 'John Smith',
    ]);

    $this->get(route('tasks.show', $task))->assertInertia(fn (Assert $page) => $page
        ->component('Tasks/Show')
        ->where('task.startedBy', 'Jane Doe')
        ->where('task.responsible', 'John Smith'));
});

test('it shows the model the agent resolved instead of the configured alias', function () {
    $task = YakTask::factory()->create(['model_used' => 'opus']);
    TaskRun::factory()->create([
        'yak_task_id' => $task->id,
        'model_usage' => [
            'claude-haiku-4-5-20251001' => ['input' => 500, 'output' => 40, 'cache_read' => 0, 'cache_creation' => 0, 'cost_usd' => 0.01],
            'claude-opus-5-5' => ['input' => 12, 'output' => 2887, 'cache_read' => 271592, 'cache_creation' => 45943, 'cost_usd' => 0.48],
        ],
    ]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('task.model', 'claude-opus-5-5')
            ->etc());
});

test('it falls back to the configured alias when no run recorded model usage', function () {
    $task = YakTask::factory()->create(['model_used' => 'opus']);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('task.model', 'opus')
            ->etc());
});
