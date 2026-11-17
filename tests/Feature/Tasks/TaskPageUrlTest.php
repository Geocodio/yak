<?php

use App\Enums\TaskMode;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\YakTask;
use App\Support\TaskPageUrl;

function threadedTask(array $attributes = []): array
{
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id, ...$attributes]);

    return [$task, $thread];
}

it('sends a work task to its thread', function () {
    [$task, $thread] = threadedTask();

    expect(TaskPageUrl::for($task))->toBe(route('threads.show', $thread))
        ->and(TaskPageUrl::label($task))->toBe('thread');
});

it('sends a research task of a conversation to its thread', function () {
    [$task, $thread] = threadedTask(['mode' => TaskMode::Research]);

    expect(TaskPageUrl::for($task))->toBe(route('threads.show', $thread));
});

it('sends a follow-up task to its thread at its own first message', function () {
    [$root, $thread] = threadedTask();
    $followUp = YakTask::factory()->create(['thread_id' => $thread->id, 'parent_task_id' => $root->id]);
    $run = TaskRun::factory()->create(['yak_task_id' => $followUp->id, 'thread_id' => $thread->id]);
    $message = ThreadTurnMessage::factory()->create(['task_run_id' => $run->id]);

    expect(TaskPageUrl::for($followUp))->toBe(route('threads.show', $thread) . '#message-' . $message->uuid);
});

it('sends a follow-up task without a recorded message to its thread alone', function () {
    [$root, $thread] = threadedTask();
    $followUp = YakTask::factory()->create(['thread_id' => $thread->id, 'parent_task_id' => $root->id]);

    expect(TaskPageUrl::for($followUp))->toBe(route('threads.show', $thread));
});

it('sends a moved task to the thread it moved to', function () {
    $old = Thread::factory()->closed()->create(['close_reason' => 'moved']);
    $new = Thread::factory()->create();
    $task = YakTask::factory()->create(['thread_id' => $new->id]);

    expect(TaskPageUrl::for($task))->toBe(route('threads.show', $new))->not->toContain($old->public_id);
});

it('maps the old anchors and query names onto the thread page', function () {
    [$task, $thread] = threadedTask();
    $base = route('threads.show', $thread);

    expect(TaskPageUrl::for($task, 'questions'))->toBe($base . '#card-question')
        ->and(TaskPageUrl::for($task, 'co-owner'))->toBe($base . '#card-co-owner')
        ->and(TaskPageUrl::for($task, null, ['t' => '95']))->toBe($base . '?walkthrough=95')
        ->and(TaskPageUrl::for($task, null, ['repo' => 'acme/widgets']))->toBe($base . '?repo=acme%2Fwidgets')
        ->and(TaskPageUrl::for($task, null, ['log' => '5', 'tab' => 'x']))->toBe($base);
});

it('builds a relative url when asked', function () {
    [$task, $thread] = threadedTask();

    expect(TaskPageUrl::for($task, 'co-owner', absolute: false))->toBe('/threads/' . $thread->public_id . '#card-co-owner');
});

it('sends a task of a legacy conversation that was not continued to the legacy thread and keeps the log', function () {
    $root = YakTask::factory()->create(['thread_id' => null]);
    $child = YakTask::factory()->create(['thread_id' => null, 'parent_task_id' => $root->id]);
    $legacy = Thread::factory()->closed()->create(['is_legacy' => true, 'legacy_root_task_id' => $root->id]);

    expect(TaskPageUrl::for($child, null, ['log' => '7']))->toBe(route('threads.show', $legacy) . '?log=7');

    $child->forceFill(['thread_id' => $legacy->id])->save();

    expect(TaskPageUrl::for($child->fresh(), null, ['log' => '7']))->toBe(route('threads.show', $legacy) . '?log=7');
});

it('sends a task of a legacy conversation that continued to its current thread and drops the log', function () {
    $root = YakTask::factory()->create(['thread_id' => null]);
    $legacy = Thread::factory()->closed()->create(['is_legacy' => true, 'legacy_root_task_id' => $root->id]);
    $continuation = Thread::factory()->create(['continued_from_thread_id' => $legacy->id]);
    $child = YakTask::factory()->create(['thread_id' => $continuation->id, 'parent_task_id' => $root->id]);

    expect(TaskPageUrl::for($child, null, ['log' => '7']))->toBe(route('threads.show', $continuation));
});

it('has no page for a work task whose thread is gone', function () {
    [$task, $thread] = threadedTask();
    $thread->delete();

    expect(TaskPageUrl::for($task->fresh()))->toBeNull()
        ->and(Thread::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('sends a review to the page of its pull request at its own run', function () {
    $task = YakTask::factory()->create(['mode' => TaskMode::Review, 'repo' => 'acme/widgets', 'pr_number' => 42]);

    expect(TaskPageUrl::for($task, 'questions', ['t' => 5, 'log' => 3]))->toBe(route('pr-reviews.for-pr', ['acme/widgets', 42]) . '#run-' . $task->public_id)
        ->and(TaskPageUrl::label($task))->toBe('review');
});

it('reads the pull request number of a review from its pull request url', function () {
    $task = YakTask::factory()->create(['mode' => TaskMode::Review, 'repo' => 'acme/widgets', 'pr_number' => null, 'pr_url' => 'https://github.com/acme/widgets/pull/9']);

    expect(TaskPageUrl::for($task))->toBe(route('pr-reviews.for-pr', ['acme/widgets', 9]) . '#run-' . $task->public_id);
});

it('falls back to the pull request url of a review that has no number', function () {
    $task = YakTask::factory()->create(['mode' => TaskMode::Review, 'repo' => 'acme/widgets', 'pr_number' => null, 'pr_url' => 'https://example.test/change/abc']);

    expect(TaskPageUrl::for($task))->toBe('https://example.test/change/abc');
});

it('has no page for a review without a pull request', function () {
    $task = YakTask::factory()->create(['mode' => TaskMode::Review, 'repo' => 'acme/widgets', 'pr_number' => null, 'pr_url' => null]);

    expect(TaskPageUrl::for($task))->toBeNull();
});

it('sends a setup run to the repository Runs tab', function () {
    $repository = Repository::factory()->create(['slug' => 'acme/widgets']);
    $task = YakTask::factory()->create(['mode' => TaskMode::Setup, 'repo' => 'acme/widgets']);

    expect(TaskPageUrl::for($task, null, ['t' => 3]))->toBe(route('repos.runs.show', [$repository, $task]))
        ->and(TaskPageUrl::label($task))->toBe('run');
});

it('sends a repository research task to the repository Runs tab', function () {
    $repository = Repository::factory()->create(['slug' => 'acme/widgets']);
    $task = YakTask::factory()->create(['mode' => TaskMode::Research, 'repo' => 'acme/widgets', 'external_id' => 'risk-profile-acme']);

    expect(TaskPageUrl::for($task))->toBe(route('repos.runs.show', [$repository, $task]));
});

it('has no page for a setup or repository research task whose repository is gone', function () {
    $setup = YakTask::factory()->create(['mode' => TaskMode::Setup, 'repo' => 'gone/repo']);
    $research = YakTask::factory()->create(['mode' => TaskMode::Research, 'repo' => 'gone/repo', 'external_id' => 'risk-profile-gone']);

    expect(TaskPageUrl::for($setup))->toBeNull()->and(TaskPageUrl::for($research))->toBeNull();
});

it('finds the page of a task by its primary key', function () {
    [$task, $thread] = threadedTask();

    expect(TaskPageUrl::forTaskId($task->id))->toBe(route('threads.show', $thread))
        ->and(TaskPageUrl::forTaskId(null))->toBeNull()
        ->and(TaskPageUrl::forTaskId(999999))->toBeNull()
        ->and(TaskPageUrl::forOrLog(YakTask::factory()->create(['thread_id' => null])))->toBe(route('threads.log'));
});
