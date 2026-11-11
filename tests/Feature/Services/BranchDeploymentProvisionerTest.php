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
