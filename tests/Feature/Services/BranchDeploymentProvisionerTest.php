<?php

use App\Enums\TaskMode;
use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\YakTask;
use App\Services\BranchDeploymentProvisioner;

beforeEach(function () {
    $this->repository = Repository::factory()->create(['slug' => 'acme/app', 'github_full_name' => 'acme/app']);
    $this->thread = Thread::factory()->create();
});

function provisionBranch($repository, string $branch = 'yak/b'): BranchDeployment
{
    return app(BranchDeploymentProvisioner::class)->provision($repository, $branch);
}

it('links the latest thread task carrying the branch', function () {
    $attrs = ['thread_id' => $this->thread->id, 'repo' => 'acme/app', 'branch_name' => 'yak/b'];
    YakTask::factory()->create($attrs);
    $latest = YakTask::factory()->create($attrs);

    expect(provisionBranch($this->repository)->yak_task_id)->toBe($latest->id);
});

it('ignores review-mode and thread-less tasks', function () {
    $attrs = ['repo' => 'acme/app', 'branch_name' => 'yak/b'];
    YakTask::factory()->create($attrs + ['thread_id' => null]);
    YakTask::factory()->create($attrs + ['thread_id' => $this->thread->id, 'mode' => TaskMode::Review]);

    expect(provisionBranch($this->repository)->yak_task_id)->toBeNull();
});

it('falls back to the thread repository row branch and its latest task', function () {
    ThreadRepository::factory()->for($this->thread)->create(['repo' => 'acme/app', 'branch' => 'yak/b']);
    YakTask::factory()->create(['thread_id' => $this->thread->id, 'repo' => 'acme/other', 'branch_name' => null]);
    $latest = YakTask::factory()->create(['thread_id' => $this->thread->id, 'repo' => 'acme/other', 'branch_name' => null]);

    expect(provisionBranch($this->repository)->yak_task_id)->toBe($latest->id);
});

it('backfills a null link on the next provision and never overwrites a link', function () {
    $deployment = provisionBranch($this->repository);
    expect($deployment->yak_task_id)->toBeNull();

    $task = YakTask::factory()->create(['thread_id' => $this->thread->id, 'repo' => 'acme/app', 'branch_name' => 'yak/b']);
    expect(provisionBranch($this->repository)->yak_task_id)->toBe($task->id);

    YakTask::factory()->create(['thread_id' => $this->thread->id, 'repo' => 'acme/app', 'branch_name' => 'yak/b']);
    expect(provisionBranch($this->repository)->yak_task_id)->toBe($task->id);
});

function branchTask(Thread $thread, array $overrides = []): YakTask
{
    return YakTask::factory()->create($overrides + ['thread_id' => $thread->id, 'repo' => 'acme/app', 'branch_name' => 'yak/b']);
}

function provisionPr($repository, ?int $number, ?string $headRepository, ?string $sha = null): BranchDeployment
{
    return app(BranchDeploymentProvisioner::class)->provision($repository, 'yak/b', $number, $headRepository, $sha);
}

it('does not link a fork head that reuses the branch name', function () {
    branchTask($this->thread);

    expect(provisionPr($this->repository, 7, 'forker/app', 'abc')->yak_task_id)->toBeNull();
});

it('links when the head repository is the base repository', function () {
    $task = branchTask($this->thread);

    expect(provisionPr($this->repository, 7, 'ACME/app')->yak_task_id)->toBe($task->id);
});

it('resolves two threads on one branch to the private one in either creation order', function (bool $privateFirst) {
    $private = Thread::factory()->private()->create();
    $team = Thread::factory()->create();

    if ($privateFirst) {
        $privateTask = branchTask($private);
        branchTask($team);
    } else {
        branchTask($team);
        $privateTask = branchTask($private);
    }

    expect(provisionPr($this->repository, null, null)->yak_task_id)->toBe($privateTask->id);
})->with([true, false]);

it('resolves thread repository fallback rows to the private thread', function () {
    $private = Thread::factory()->private()->create();
    $team = Thread::factory()->create();
    foreach ([$team, $private] as $thread) {
        ThreadRepository::factory()->for($thread)->create(['repo' => 'acme/app', 'branch' => 'yak/b']);
    }
    YakTask::factory()->create(['thread_id' => $team->id, 'repo' => 'acme/other', 'branch_name' => null]);
    $privateTask = YakTask::factory()->create(['thread_id' => $private->id, 'repo' => 'acme/other', 'branch_name' => null]);

    expect(provisionPr($this->repository, null, null)->yak_task_id)->toBe($privateTask->id);
});

it('prefers the task whose pr_url matches the PR number', function () {
    $team = Thread::factory()->create();
    $matching = branchTask($this->thread, ['pr_url' => 'https://github.com/acme/app/pull/7']);
    branchTask($team);

    expect(provisionPr($this->repository, 7, 'acme/app')->yak_task_id)->toBe($matching->id);
});

it('lets a PR match outrank privacy', function () {
    $private = Thread::factory()->private()->create();
    $matching = branchTask($this->thread, ['pr_url' => 'https://github.com/acme/app/pull/7']);
    branchTask($private);

    expect(provisionPr($this->repository, 7, 'acme/app')->yak_task_id)->toBe($matching->id);
});

it('prefers thread repository rows matching last_pushed_sha or pull_request_url', function (string $column, string $value) {
    $other = Thread::factory()->create();
    ThreadRepository::factory()->for($other)->create(['repo' => 'acme/app', 'branch' => 'yak/b']);
    ThreadRepository::factory()->for($this->thread)->create(['repo' => 'acme/app', 'branch' => 'yak/b', $column => $value]);
    YakTask::factory()->create(['thread_id' => $other->id, 'repo' => 'acme/other', 'branch_name' => null]);
    $expected = YakTask::factory()->create(['thread_id' => $this->thread->id, 'repo' => 'acme/other', 'branch_name' => null]);
    // The non-matching thread is newer and would win the newest-first tiebreak.

    expect(provisionPr($this->repository, 7, 'acme/app', 'abc')->yak_task_id)->toBe($expected->id);
})->with([
    ['last_pushed_sha', 'abc'],
    ['pull_request_url', 'https://github.com/acme/app/pull/7'],
]);
