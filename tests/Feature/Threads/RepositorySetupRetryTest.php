<?php

use App\Enums\RepositorySetupStatus;
use App\Enums\ThreadStatus;
use App\Events\ThreadRepositoryChanged;
use App\Exceptions\ThreadActionRefused;
use App\Jobs\RetryRepositorySetupJob;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Services\Threads\Repos\RepositorySetupRetry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BusyRow;

beforeEach(function () {
    Queue::fake();
    $this->owner = User::factory()->create();
    $this->thread = Thread::factory()->create(['owner_user_id' => $this->owner->id]);
});

function failedExtraRepository(Thread $thread, string $repo = 'example/web'): ThreadRepository
{
    return ThreadRepository::factory()->changes()->create(['thread_id' => $thread->id, 'repo' => $repo, 'setup_status' => RepositorySetupStatus::Failed, 'setup_error' => 'boom']);
}

it('retries a failed setup once', function () {
    Event::fake([ThreadRepositoryChanged::class]);
    $row = failedExtraRepository($this->thread);

    expect(app(RepositorySetupRetry::class)->retry($this->thread, $row))->toBeTrue()
        ->and($row->fresh()->setup_status)->toBe(RepositorySetupStatus::Pending)
        ->and($row->fresh()->setup_error)->toBeNull();
    Queue::assertPushed(RetryRepositorySetupJob::class, 1);
    Queue::assertPushed(RetryRepositorySetupJob::class, fn (RetryRepositorySetupJob $job): bool => $job->afterCommit === true);
    Event::assertDispatched(ThreadRepositoryChanged::class, 1);

    expect(app(RepositorySetupRetry::class)->retry($this->thread, $row->fresh()))->toBeFalse();
    Queue::assertPushed(RetryRepositorySetupJob::class, 1);
});

it('refuses a primary, a ready row and a closed thread', function () {
    $primary = ThreadRepository::factory()->create(['thread_id' => $this->thread->id, 'repo' => 'example/api', 'role' => 'primary']);
    $ready = ThreadRepository::factory()->changes()->create(['thread_id' => $this->thread->id, 'repo' => 'example/web', 'setup_status' => RepositorySetupStatus::Ready]);

    foreach ([$primary, $ready] as $row) {
        expect(fn () => app(RepositorySetupRetry::class)->retry($this->thread, $row))
            ->toThrow(fn (ThreadActionRefused $refusal) => expect($refusal->errorCode)->toBe('nothing_to_retry')->and($refusal->getMessage())->toBe('Only a failed setup of an extra repository can be retried.'));
    }

    $row = failedExtraRepository($this->thread, 'example/closed');
    $this->thread->forceFill(['status' => ThreadStatus::Closed, 'closed_at' => now(), 'close_reason' => 'manual'])->save();

    expect(fn () => app(RepositorySetupRetry::class)->retry($this->thread->fresh(), $row))
        ->toThrow(fn (ThreadActionRefused $refusal) => expect($refusal->errorCode)->toBe('thread_closed')->and($refusal->getMessage())->toBe('This thread is closed.'));
    expect($row->fresh()->setup_status)->toBe(RepositorySetupStatus::Failed);
    Queue::assertNothingPushed();
});

it('answers busy when the row lock wait runs out', function () {
    $row = failedExtraRepository($this->thread);
    BusyRow::on(ThreadRepository::class, $row);

    expect(fn () => app(RepositorySetupRetry::class)->retry($this->thread, $row))
        ->toThrow(fn (HttpException $exception) => expect($exception->getStatusCode())->toBe(503));
    expect($row->fresh()->setup_status)->toBe(RepositorySetupStatus::Failed);
    Queue::assertNothingPushed();
});

it('keeps the web sentences and accepts a retry that already runs', function () {
    $owner = $this->owner;
    $primary = ThreadRepository::factory()->create(['thread_id' => $this->thread->id, 'repo' => 'example/api', 'role' => 'primary']);
    $ready = ThreadRepository::factory()->changes()->create(['thread_id' => $this->thread->id, 'repo' => 'example/web', 'setup_status' => RepositorySetupStatus::Ready]);
    $pending = ThreadRepository::factory()->changes()->create(['thread_id' => $this->thread->id, 'repo' => 'example/docs', 'setup_status' => RepositorySetupStatus::Pending]);

    $this->actingAs($owner)->postJson(route('threads.repositories.retry', [$this->thread, $primary]))->assertUnprocessable()->assertJsonValidationErrors(['repository' => 'Only an extra repository has a setup to retry.']);
    $this->actingAs($owner)->postJson(route('threads.repositories.retry', [$this->thread, $ready]))->assertUnprocessable()->assertJsonValidationErrors(['repository' => 'Only a failed setup can be retried.']);
    $this->actingAs($owner)->postJson(route('threads.repositories.retry', [$this->thread, $pending]))->assertRedirect();
    Queue::assertNothingPushed();
});
