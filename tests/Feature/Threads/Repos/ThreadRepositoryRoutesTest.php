<?php

use App\DataTransferObjects\RepositorySetupResult;
use App\Enums\RepositoryRole;
use App\Enums\RepositorySetupStatus;
use App\Jobs\RetryRepositorySetupJob;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\ThreadShare;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\Repos\ExtraRepositorySetup;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->owner = User::factory()->create();
    $this->thread = Thread::factory()->create(['owner_user_id' => $this->owner->id]);
});

function threadRepositoryRow(Thread $thread, string $state = 'changes', array $attributes = []): ThreadRepository
{
    return ThreadRepository::factory()->{$state}()->create(['thread_id' => $thread->id, 'repo' => 'acme/web', ...$attributes]);
}

it('promotes and demotes through http', function () {
    $row = threadRepositoryRow($this->thread, 'context');

    $this->actingAs($this->owner)->patchJson(route('threads.repositories.update', [$this->thread, $row]), ['role' => 'changes'])->assertRedirect();
    expect($row->fresh()->role)->toBe(RepositoryRole::Changes);

    $this->actingAs($this->owner)->patchJson(route('threads.repositories.update', [$this->thread, $row]), ['role' => 'context'])->assertRedirect();
    expect($row->fresh()->role)->toBe(RepositoryRole::Context);
});

it('refuses to demote a repo with an open pull request', function () {
    $task = YakTask::factory()->create(['thread_id' => $this->thread->id, 'pr_url' => 'https://github.com/acme/web/pull/3']);
    $row = threadRepositoryRow($this->thread, 'changes', ['pull_request_url' => $task->pr_url]);

    $this->actingAs($this->owner)->patchJson(route('threads.repositories.update', [$this->thread, $row]), ['role' => 'context'])
        ->assertStatus(422)->assertJsonValidationErrors(['role' => 'Close the pull request first, then make this repo context only.']);
    expect($row->fresh()->role)->toBe(RepositoryRole::Changes);
});

it('rejects an unknown role', function () {
    $row = threadRepositoryRow($this->thread);

    $this->actingAs($this->owner)->patchJson(route('threads.repositories.update', [$this->thread, $row]), ['role' => 'primary'])->assertStatus(422);
});

it('forbids a viewer without drive and hides a private thread', function () {
    $row = threadRepositoryRow($this->thread);
    $stranger = User::factory()->create();
    $this->thread->update(['visibility' => 'private']);

    $this->actingAs($stranger)->patchJson(route('threads.repositories.update', [$this->thread, $row]), ['role' => 'context'])->assertNotFound();

    ThreadShare::factory()->create(['thread_id' => $this->thread->id, 'user_id' => $stranger->id]);
    $this->actingAs($stranger)->patchJson(route('threads.repositories.update', [$this->thread, $row]), ['role' => 'context'])->assertForbidden();
    $this->actingAs($stranger)->postJson(route('threads.repositories.retry', [$this->thread, $row]))->assertForbidden();
});

it('does not reach a repository of another thread', function () {
    $row = threadRepositoryRow(Thread::factory()->create());

    $this->actingAs($this->owner)->patchJson(route('threads.repositories.update', [$this->thread, $row]), ['role' => 'context'])->assertNotFound();
});

it('retry resets the row and dispatches the job', function () {
    $row = threadRepositoryRow($this->thread, 'changes', ['setup_status' => RepositorySetupStatus::Failed, 'setup_error' => 'boom']);

    $this->actingAs($this->owner)->postJson(route('threads.repositories.retry', [$this->thread, $row]))->assertRedirect();

    expect($row->fresh()->setup_status)->toBe(RepositorySetupStatus::Pending)->and($row->fresh()->setup_error)->toBeNull();
    Queue::assertPushed(RetryRepositorySetupJob::class);
});

it('steers the agent after a successful retry', function () {
    $row = threadRepositoryRow($this->thread, 'changes', ['setup_status' => RepositorySetupStatus::Failed, 'setup_error' => 'boom']);
    $this->mock(ExtraRepositorySetup::class)->shouldReceive('setUp')->once()->andReturn(new RepositorySetupResult('acme/web', RepositorySetupStatus::Ready, null, '', 0.0));
    $this->mock(ThreadTurns::class)->shouldReceive('steer')->once()->with(Mockery::type(Thread::class), 'Setup for acme/web finished. You can continue.', null, null);

    (new RetryRepositorySetupJob($this->thread, $row))->handle(app(ExtraRepositorySetup::class), app(ThreadTurns::class));
});

it('stays quiet when the retry fails again', function () {
    $row = threadRepositoryRow($this->thread, 'changes', ['setup_status' => RepositorySetupStatus::Failed, 'setup_error' => 'boom']);
    $this->mock(ExtraRepositorySetup::class)->shouldReceive('setUp')->once()->andReturn(new RepositorySetupResult('acme/web', RepositorySetupStatus::Failed, 'boom', '', 0.0));
    $this->mock(ThreadTurns::class)->shouldNotReceive('steer');

    (new RetryRepositorySetupJob($this->thread, $row))->handle(app(ExtraRepositorySetup::class), app(ThreadTurns::class));
});
