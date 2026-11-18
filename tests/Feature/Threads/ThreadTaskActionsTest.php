<?php

use App\DataTransferObjects\ClarificationQuestion;
use App\DataTransferObjects\ResolvedAccount;
use App\Enums\StopKind;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Enums\TurnState;
use App\Jobs\RenderVideoJob;
use App\Jobs\RunYakJob;
use App\Jobs\SetupYakJob;
use App\Models\Artifact;
use App\Models\PendingSteeringMessage;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\QueuedMessages;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__ . '/../../Support/ThreadTurnHelpers.php';
require_once __DIR__ . '/../../Support/ThreadTurnsHelpers.php';

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    Process::fake(['*' => Process::result(exitCode: 0)]);
    Repository::factory()->create(['slug' => 'web', 'is_active' => true]);
    Repository::factory()->create(['slug' => 'api', 'is_active' => true]);
    $this->user = User::factory()->create();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function threadActionTask(array $attributes = []): YakTask
{
    return YakTask::factory()->create([
        'mode' => TaskMode::Fix,
        'repo' => 'web',
        'pr_url' => null,
        'status' => TaskStatus::Failed,
        ...$attributes,
    ]);
}

function threadActionThread(YakTask $task): Thread
{
    return Thread::query()->findOrFail($task->thread_id);
}

dataset('thread actions', [
    'retry' => ['threads.retry'],
    'cancel' => ['threads.cancel'],
    'move' => ['threads.move'],
    'setup-and-continue' => ['threads.setup-and-continue'],
    'request-review' => ['threads.request-review'],
    'retry-render' => ['threads.retry-render'],
]);

it('refuses a viewer who cannot drive and hides the thread from a stranger', function (string $name) {
    $task = threadActionTask();
    $thread = threadActionThread($task)->forceFill(['visibility' => 'private', 'owner_user_id' => User::factory()->create()->id]);
    $thread->save();
    $viewer = User::factory()->create();
    ThreadShare::query()->create(['thread_id' => $thread->id, 'user_id' => $viewer->id]);

    $this->actingAs($viewer)->post(route($name, $thread), ['repo' => 'api'])->assertForbidden();
    $this->actingAs(User::factory()->create())->post(route($name, $thread), ['repo' => 'api'])->assertNotFound();

    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
    Queue::assertNothingPushed();
})->with('thread actions');

it('answers a thread without a task with the nothing-to sentence', function (string $name, string $verb) {
    $thread = Thread::factory()->create(['repo' => 'web']);

    $this->actingAs($this->user)->postJson(route($name, $thread), ['repo' => 'api'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('thread');

    $this->actingAs($this->user)->post(route($name, $thread), ['repo' => 'api'])
        ->assertSessionHas('error', "This thread has nothing to {$verb}.");
})->with([
    ['threads.retry', 'retry'],
    ['threads.setup-and-continue', 'retry'],
    ['threads.cancel', 'cancel'],
    ['threads.move', 'move'],
    ['threads.request-review', 'review'],
    ['threads.retry-render', 'render'],
]);

it('refuses a closed thread', function (string $name) {
    $task = threadActionTask();
    $thread = threadActionThread($task);
    $thread->update(['status' => 'closed', 'closed_at' => now(), 'close_reason' => 'merged']);
    $task->update(['pr_url' => 'https://github.com/o/r/pull/1']);

    $this->actingAs($this->user)->post(route($name, $thread), ['repo' => 'api'])
        ->assertRedirect(route('threads.show', $thread))
        ->assertSessionHas('error', 'This thread is closed.');

    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
    Queue::assertNothingPushed();
})->with('thread actions');

it('does not offer retry on a closed legacy thread', function () {
    $task = threadActionTask();
    $thread = threadActionThread($task);
    $thread->update(['is_legacy' => true, 'status' => 'closed', 'closed_at' => now(), 'close_reason' => 'merged']);

    $this->actingAs($this->user)->post(route('threads.retry', $thread))
        ->assertSessionHas('error', 'This thread has nothing to retry.');

    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
});

it('asks for a repository on a thread without one', function (string $name) {
    $task = threadActionTask(['pr_url' => 'https://github.com/o/r/pull/1']);
    threadActionThread($task)->update(['repo' => Thread::UNKNOWN_REPOSITORY]);

    $this->actingAs($this->user)->post(route($name, threadActionThread($task)))
        ->assertSessionHas('error', 'Pick a repository first.');

    Queue::assertNothingPushed();
})->with(['threads.retry', 'threads.setup-and-continue', 'threads.request-review', 'threads.retry-render']);

it('retries a failed head task like the task route and clears its stop', function () {
    $task = threadActionTask(['error_log' => 'boom', 'stop_kind' => StopKind::YakStopped, 'stop_summary' => 'Stopped.']);
    $thread = threadActionThread($task);

    $this->actingAs($this->user)->post(route('threads.retry', $thread))->assertRedirect(route('threads.show', $thread));

    Queue::assertPushed(RunYakJob::class);
    expect($task->fresh()->status)->toBe(TaskStatus::Pending)
        ->and($task->fresh()->error_log)->toBeNull()
        ->and($task->fresh()->stop_kind)->toBeNull()
        ->and($task->fresh()->stop_summary)->toBeNull();
});

it('retries the head task, not the first one', function () {
    $first = threadActionTask(['status' => TaskStatus::Success]);
    $thread = threadActionThread($first);
    $head = threadActionTask(['thread_id' => $thread->id, 'parent_task_id' => $first->id]);

    $this->actingAs($this->user)->post(route('threads.retry', $thread));

    expect($head->fresh()->status)->toBe(TaskStatus::Pending)
        ->and($first->fresh()->status)->toBe(TaskStatus::Success);
});

it('stops the running turn and then cancels the head task', function () {
    [$thread, $run] = runningTurnFor();
    $thread->update(['repo' => 'web']);
    $task = threadActionTask(['thread_id' => $thread->id, 'status' => TaskStatus::Running]);
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('interrupt')->once();

    $this->actingAs($this->user)->post(route('threads.cancel', $thread))->assertRedirect(route('threads.show', $thread));

    expect($run->refresh()->turn_state)->toBe(TurnState::Interrupted)
        ->and(ThreadTurns::activeTurn($thread))->toBeNull()
        ->and($task->fresh()->status)->toBe(TaskStatus::Cancelled);
});

it('refuses to cancel a finished task and keeps it', function () {
    $task = threadActionTask(['status' => TaskStatus::Success]);

    $this->actingAs($this->user)->post(route('threads.cancel', threadActionThread($task)))
        ->assertSessionHas('error', 'This task cannot be cancelled right now.');

    expect($task->fresh()->status)->toBe(TaskStatus::Success);
});

it('starts repository setup and marks the head task to continue', function () {
    $repository = Repository::factory()->create(['slug' => 'fresh', 'setup_status' => 'failed', 'sandbox_snapshot' => null, 'is_active' => true]);
    $task = threadActionTask(['repo' => $repository->slug, 'error_log' => 'not set up']);

    $this->actingAs($this->user)->post(route('threads.setup-and-continue', threadActionThread($task)))->assertSessionHas('success');

    Queue::assertPushed(SetupYakJob::class, 1);
    expect($task->fresh()->resume_after_setup_at)->not->toBeNull()
        ->and($repository->fresh()->setup_status)->toBe('pending');
});

it('re-renders the walkthrough of the pull request task', function () {
    $task = threadActionTask(['status' => TaskStatus::Success, 'branch_name' => 'yak/x']);
    Artifact::factory()->for($task, 'task')->create(['role' => 'raw', 'type' => 'video']);

    $this->actingAs($this->user)->post(route('threads.retry-render', threadActionThread($task)))
        ->assertRedirect(route('threads.show', threadActionThread($task)))
        ->assertSessionHas('success', 'Re-rendering the walkthrough.');

    Queue::assertPushed(RenderVideoJob::class);
});

it('moves the head task to another repository and redirects to the new thread', function () {
    $task = threadActionTask();
    $old = threadActionThread($task);

    $response = $this->actingAs($this->user)->post(route('threads.move', $old), ['repo' => 'api']);

    $new = threadActionThread($task->fresh());
    $response->assertRedirect(route('threads.show', $new))->assertSessionHas('success', 'Task moved to api.');

    expect($new->id)->not->toBe($old->id)
        ->and($new->repo)->toBe('api')
        ->and($old->fresh()->isOpen())->toBeFalse()
        ->and($task->fresh()->repo)->toBe('api');
});

it('keeps a private thread private after the move', function () {
    $task = threadActionTask();
    $old = threadActionThread($task);
    $owner = User::factory()->create();
    $old->forceFill(['visibility' => 'private', 'owner_user_id' => $owner->id])->save();
    $sharedWith = User::factory()->create();
    ThreadShare::query()->create(['thread_id' => $old->id, 'user_id' => $sharedWith->id]);

    $this->actingAs($owner)->post(route('threads.move', $old), ['repo' => 'api'])->assertRedirect();

    $new = threadActionThread($task->fresh());
    expect($new->visibility->value)->toBe('private')
        ->and($new->owner_user_id)->toBe($owner->id)
        ->and($new->shares()->pluck('user_id')->all())->toBe([$sharedWith->id]);
});

it('rejects a move to an unknown repository', function () {
    $task = threadActionTask();

    $this->actingAs($this->user)->postJson(route('threads.move', threadActionThread($task)), ['repo' => 'nope'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('repo');

    expect($task->fresh()->repo)->toBe('web');
});

it('stops a running turn and moves the task', function () {
    [$old, $run] = runningTurnFor();
    $old->update(['repo' => 'web']);
    $task = threadActionTask(['thread_id' => $old->id, 'status' => TaskStatus::Running]);
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('interrupt')->once();
    $fakes['sandbox']->shouldReceive('destroy')->once();
    $fakes['resolver']->shouldReceive('forNewThread')->andReturn(new ResolvedAccount(null, 'sonnet'));

    $this->actingAs($this->user)->post(route('threads.move', $old), ['repo' => 'api'])->assertRedirect();

    expect($run->refresh()->turn_state)->toBe(TurnState::Interrupted)
        ->and($task->fresh()->thread_id)->not->toBe($old->id)
        ->and($task->fresh()->repo)->toBe('api');
});

it('attaches the repository when the thread has none', function () {
    $task = threadActionTask(['repo' => Thread::UNKNOWN_REPOSITORY]);
    $thread = threadActionThread($task);
    $thread->update(['repo' => Thread::UNKNOWN_REPOSITORY]);

    $this->actingAs($this->user)->post(route('threads.move', $thread), ['repo' => 'api'])->assertRedirect(route('threads.show', $thread));

    expect($thread->fresh()->repo)->toBe('api')
        ->and($thread->fresh()->isOpen())->toBeTrue();
});

it('refuses to stop the thread for a viewer who cannot drive', function () {
    $thread = Thread::factory()->private(User::factory()->create())->create();
    $viewer = User::factory()->create();
    ThreadShare::query()->create(['thread_id' => $thread->id, 'user_id' => $viewer->id]);
    $this->mock(ThreadTurns::class)->shouldReceive('stop')->never();

    $this->actingAs($viewer)->post(route('threads.stop', $thread))->assertForbidden();
    $this->actingAs(User::factory()->create())->post(route('threads.stop', $thread))->assertNotFound();
});

function threadActionQueuedMessage(YakTask $root, ?User $author = null): PendingSteeringMessage
{
    return PendingSteeringMessage::create(['author_user_id' => $author?->id, 'mode' => 'queue', 'root_task_id' => $root->id, 'text' => 'and the footer', 'source' => 'web', 'uuid' => (string) Str::uuid()]);
}

it('switches a queued message and edits it, by row id or uuid', function () {
    $task = threadActionTask(['status' => TaskStatus::Running]);
    $thread = threadActionThread($task);
    $message = threadActionQueuedMessage($task, $this->user);

    $this->actingAs($this->user)->patch(route('threads.queued-messages.update', [$thread, $message->id]), ['mode' => 'queue', 'text' => 'and the header'])
        ->assertRedirect(route('threads.show', $thread));
    expect($message->fresh()->text)->toBe('and the header');

    $this->actingAs($this->user)->delete(route('threads.queued-messages.destroy', [$thread, $message->uuid]))->assertRedirect(route('threads.show', $thread));
    expect(PendingSteeringMessage::query()->whereKey($message->id)->exists())->toBeFalse();
});

it('answers a queued message of another thread like a missing one and keeps it', function () {
    $task = threadActionTask(['status' => TaskStatus::Running]);
    $other = threadActionTask(['status' => TaskStatus::Running]);
    $foreign = threadActionQueuedMessage($other);

    $this->actingAs($this->user)->patch(route('threads.queued-messages.update', [threadActionThread($task), $foreign->id]), ['mode' => 'queue', 'text' => 'hijacked'])->assertSessionHas('error', QueuedMessages::DELIVERED_SENTENCE);
    $this->actingAs($this->user)->delete(route('threads.queued-messages.destroy', [threadActionThread($task), $foreign->uuid]))->assertSessionHas('error', QueuedMessages::DELIVERED_SENTENCE);

    expect($foreign->fresh()->text)->toBe('and the footer');
});

it('keeps queued messages behind drive rights and visibility', function () {
    $task = threadActionTask(['status' => TaskStatus::Running]);
    $thread = threadActionThread($task)->forceFill(['visibility' => 'private', 'owner_user_id' => User::factory()->create()->id]);
    $thread->save();
    $message = threadActionQueuedMessage($task);
    $viewer = User::factory()->create();
    ThreadShare::query()->create(['thread_id' => $thread->id, 'user_id' => $viewer->id]);

    $this->actingAs($viewer)->delete(route('threads.queued-messages.destroy', [$thread, $message->id]))->assertForbidden();
    $this->actingAs(User::factory()->create())->delete(route('threads.queued-messages.destroy', [$thread, $message->id]))->assertNotFound();

    expect(PendingSteeringMessage::query()->whereKey($message->id)->exists())->toBeTrue();
});

it('answers a gone queued message with the delivered sentence', function () {
    $task = threadActionTask(['status' => TaskStatus::Running]);

    $this->actingAs($this->user)->delete(route('threads.queued-messages.destroy', [threadActionThread($task), 999999]))
        ->assertSessionHas('error');
});

it('answers the open questions of the head task from the thread', function () {
    $task = threadActionTask(['status' => TaskStatus::AwaitingClarification, 'clarification_expires_at' => now()->addDay()]);
    $task->recordClarificationRound([
        ClarificationQuestion::fromArray([
            'id' => 'scope', 'header' => 'Scope', 'question' => 'Which scope?',
            'options' => [['label' => 'Small'], ['label' => 'Large']], 'multi_select' => false,
        ]),
    ], 'One decision.');
    $thread = threadActionThread($task);

    $this->actingAs($this->user)->post(route('threads.clarification-answers.store', $thread), ['answers' => ['scope' => ['choices' => ['Small'], 'other' => null]]])
        ->assertRedirect(route('threads.show', $thread))
        ->assertSessionHas('success', 'Sent to Yak.');

    expect($task->fresh()->status)->toBe(TaskStatus::Pending);
    Queue::assertPushed(RunYakJob::class);

    $this->actingAs($this->user)->post(route('threads.clarification-answers.store', $thread), ['answers' => ['scope' => ['choices' => ['Large'], 'other' => null]]])
        ->assertRedirect(route('threads.show', $thread));
});

it('refuses clarification answers from a viewer who cannot drive', function () {
    $task = threadActionTask(['status' => TaskStatus::AwaitingClarification]);
    $thread = threadActionThread($task)->forceFill(['visibility' => 'private', 'owner_user_id' => User::factory()->create()->id]);
    $thread->save();
    $viewer = User::factory()->create();
    ThreadShare::query()->create(['thread_id' => $thread->id, 'user_id' => $viewer->id]);

    $this->actingAs($viewer)->post(route('threads.clarification-answers.store', $thread), ['answers' => []])->assertForbidden();
    $this->actingAs(User::factory()->create())->post(route('threads.clarification-answers.store', $thread), ['answers' => []])->assertNotFound();
});

it('answers clarification answers on a thread without a task with a flashed refusal', function () {
    $thread = Thread::factory()->create(['repo' => 'web']);

    $this->actingAs($this->user)->post(route('threads.clarification-answers.store', $thread), ['answers' => ['x' => ['choices' => ['a']]]])
        ->assertRedirect()
        ->assertSessionHas('error');
});

function threadActionReviewTask(): YakTask
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privateKey);
    config()->set('yak.channels.github.app_id', '999');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 12345);
    Repository::factory()->create(['slug' => 'geocodio/api', 'is_active' => true, 'pr_review_enabled' => true, 'git_url' => 'https://github.com/geocodio/api.git', 'github_full_name' => 'geocodio/api']);
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'tok', 'expires_at' => now()->addHour()->toIso8601String()]),
        'api.github.com/repos/geocodio/api/pulls/77' => Http::response([
            'html_url' => 'https://github.com/geocodio/api/pull/77', 'number' => 77, 'title' => 'Some PR', 'body' => '', 'state' => 'open', 'draft' => false,
            'user' => ['login' => 'mathias'], 'head' => ['ref' => 'feat/x', 'sha' => 'new-head-sha'], 'base' => ['ref' => 'main', 'sha' => 'base-sha'],
        ]),
    ]);

    return threadActionTask(['repo' => 'geocodio/api', 'status' => TaskStatus::Success, 'branch_name' => 'yak/x', 'pr_url' => 'https://github.com/geocodio/api/pull/77', 'pr_number' => 77]);
}

it('requests a review of the pull request of the thread', function () {
    $task = threadActionReviewTask();
    $thread = threadActionThread($task);

    $this->actingAs($this->user)->post(route('threads.request-review', $thread))->assertRedirect()->assertSessionHas('success');

    $review = YakTask::query()->where('mode', TaskMode::Review)->where('id', '!=', $task->id)->latest('id')->first();
    expect($review)->not->toBeNull()
        ->and(json_decode((string) $review->context, true)['review_scope'])->toBe('full');
});

it('answers a thread without a pull request with nothing to review', function () {
    $task = threadActionTask();

    $this->actingAs($this->user)->post(route('threads.request-review', threadActionThread($task)))
        ->assertSessionHas('error', 'This thread has nothing to review.');
});
