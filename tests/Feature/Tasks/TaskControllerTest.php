<?php

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Models\Artifact;
use App\Models\BranchDeployment;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\Repository;
use App\Models\TaskLog;
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

test('composer state is clarification while awaiting clarification', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::AwaitingClarification]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page->where('composer.state', 'clarification'));
});

test('composer state is follow_up for a success task with an open pr', function () {
    $task = YakTask::factory()->success()->create();

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page->where('composer.state', 'follow_up'));
});

test('composer state is disabled_failed for a failed task', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Failed]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page->where('composer.state', 'disabled_failed'));
});

test('composer state is disabled_closed for a success task with no pr', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'pr_url' => null]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page->where('composer.state', 'disabled_closed'));
});

test('transcript is omitted from a normal load and present with a log query param', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()]);
    $log = TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'attempt_number' => 1,
        'message' => 'Ran a command',
        'metadata' => ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => 'ls -la'], 'output' => 'total 0'],
    ]);

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page->missing('transcript'));

    $this->get(route('tasks.show', [$task, 'log' => $log->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('transcript')
            ->where('transcriptLogId', $log->id));
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

test('activity rows tag consecutive assistant entries with a shared group', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => now()]);

    foreach (['thinking one', 'thinking two', 'thinking three'] as $message) {
        TaskLog::factory()->create([
            'yak_task_id' => $task->id,
            'attempt_number' => 1,
            'message' => $message,
            'level' => 'info',
            'metadata' => ['type' => 'assistant'],
        ]);
    }

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->has('activity.rows', 3)
            ->where('activity.rows.0.group', 0)
            ->where('activity.rows.1.group', 0)
            ->where('activity.rows.2.group', 0));
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

test('poll interval is fast while the task is active and null once it is done', function () {
    $running = YakTask::factory()->create(['status' => TaskStatus::Running]);
    $this->get(route('tasks.show', $running))
        ->assertInertia(fn (Assert $page) => $page->where('pollInterval', 5000));

    $success = YakTask::factory()->create(['status' => TaskStatus::Success]);
    $this->get(route('tasks.show', $success))
        ->assertInertia(fn (Assert $page) => $page->where('pollInterval', null));
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
