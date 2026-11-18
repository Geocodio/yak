<?php

use App\DataTransferObjects\ThreadMessageInput;
use App\Enums\CoOwnerRequestStatus;
use App\Enums\MessageDelivery;
use App\Enums\StopKind;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Exceptions\ThreadActionRefused;
use App\Http\Resources\ThreadCardsData;
use App\Jobs\RunYakJob;
use App\Models\CoOwnerRequest;
use App\Models\PendingSteeringMessage;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Tasks\StoppedTaskReplies;
use App\Services\Threads\ThreadFactory;
use App\Services\Threads\ThreadMessageSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

require_once __DIR__ . '/../../Support/AppApiHelpers.php';

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    $this->web = Repository::factory()->create(['slug' => 'web', 'is_active' => true]);
    $this->requester = User::factory()->create();
});

function roundOneWithoutRepository(User $requester, ?array $options): array
{
    $task = YakTask::factory()->create(['repo' => 'unknown', 'status' => TaskStatus::AwaitingClarification, 'started_by_user_id' => $requester->id, 'clarification_options' => $options, 'session_id' => null]);

    return [app(ThreadFactory::class)->forRequestWithoutRepository($task), $task];
}

it('attaches a chosen active repository when the task offered no options, and shows the same options on the card', function () {
    [$thread, $task] = roundOneWithoutRepository($this->requester, null);

    expect(ThreadCardsData::for($thread, $this->requester)['repositoryQuestion']['options'])->toBe(['web']);

    $this->actingAs($this->requester)->post(route('threads.repository-answer.store', $thread), ['repo' => 'web'])
        ->assertSessionHas('success', 'Starting in web.');

    expect($thread->fresh()->repo)->toBe('web')->and($task->fresh()->status)->toBe(TaskStatus::Pending);
    Queue::assertPushed(RunYakJob::class, 1);
});

it('refuses an offered repository that has since become inactive, attaching nothing', function () {
    Repository::factory()->create(['slug' => 'old', 'is_active' => false]);
    [$thread, $task] = roundOneWithoutRepository($this->requester, ['old', 'web']);

    expect(ThreadCardsData::for($thread, $this->requester)['repositoryQuestion']['options'])->toBe(['web']);

    $this->actingAs($this->requester)->post(route('threads.repository-answer.store', $thread), ['repo' => 'old'])
        ->assertSessionHas('error', 'Yak could not start in that repository. Pick another one.')
        ->assertSessionMissing('success');

    expect($thread->fresh()->hasRepository())->toBeFalse();
    Queue::assertNothingPushed();
});

/**
 * @param  list<Thread>  $threads
 */
function roundOneQueryCount(array $threads, User $viewer): int
{
    DB::enableQueryLog();
    DB::flushQueryLog();
    ThreadCardsData::needsYouReasons(collect($threads)->map(fn (Thread $thread): Thread => Thread::query()->withoutGlobalScopes()->findOrFail($thread->id)), $viewer);
    $count = count(DB::getQueryLog()) - count($threads);
    DB::disableQueryLog();

    return $count;
}

it('keeps the query count the same for sub-threads and private threads however many there are', function () {
    $viewer = User::factory()->create();
    $owner = User::factory()->create();
    $make = function () use ($viewer, $owner): array {
        $root = Thread::factory()->create(['visibility' => 'private', 'owner_user_id' => $owner->id, 'created_by_user_id' => $owner->id]);
        ThreadShare::query()->create(['thread_id' => $root->id, 'user_id' => $viewer->id]);
        $child = Thread::factory()->create(['root_thread_id' => $root->id, 'parent_thread_id' => $root->id, 'visibility' => 'private', 'owner_user_id' => $owner->id]);
        YakTask::factory()->create(['thread_id' => $child->id, 'started_by_user_id' => $owner->id]);

        return [$root, $child];
    };
    $few = [...$make()];
    $many = [...$few, ...$make(), ...$make(), ...$make()];

    expect(roundOneQueryCount($many, $viewer))->toBe(roundOneQueryCount($few, $viewer));
});

it('gives null for a thread the viewer cannot see, and the single and batch forms agree', function () {
    $coOwner = User::factory()->create();
    $owner = User::factory()->create();
    $task = YakTask::factory()->awaitingCoOwner()->create(['started_by_user_id' => $owner->id]);
    $thread = Thread::query()->findOrFail($task->thread_id);
    $thread->forceFill(['visibility' => 'private', 'owner_user_id' => $owner->id])->save();
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'asked_user_ids' => [$coOwner->id]]);
    $thread = $thread->fresh();

    expect(ThreadCardsData::needsYouReason($thread, $coOwner))->toBeNull()
        ->and(ThreadCardsData::needsYouReasons(collect([$thread]), $coOwner)[$thread->id])->toBeNull();

    ThreadShare::query()->create(['thread_id' => $thread->id, 'user_id' => $coOwner->id]);

    expect(ThreadCardsData::needsYouReason($thread->fresh(), $coOwner))->toBe('co_owner')
        ->and(ThreadCardsData::needsYouReasons(collect([$thread->fresh()]), $coOwner)[$thread->id])->toBe('co_owner');
});

it('only counts the latest co-owner request of the thread', function () {
    $coOwner = User::factory()->create();
    $task = YakTask::factory()->awaitingCoOwner()->create();
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'asked_user_ids' => [$coOwner->id]]);
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'asked_user_ids' => [], 'status' => CoOwnerRequestStatus::Pending]);
    $thread = Thread::query()->findOrFail($task->thread_id);

    expect(ThreadCardsData::needsYouReason($thread, $coOwner))->toBeNull();
});

it('gives repository_question only when the page would show the card', function () {
    $task = YakTask::factory()->create(['repo' => 'unknown', 'status' => TaskStatus::Pending, 'started_by_user_id' => $this->requester->id, 'session_id' => 'abc', 'clarification_options' => null]);
    $thread = app(ThreadFactory::class)->forRequestWithoutRepository($task);

    expect(ThreadCardsData::for($thread, $this->requester)['repositoryQuestion'])->toBeNull()
        ->and(ThreadCardsData::needsYouReason($thread, $this->requester))->toBeNull();

    [$offering] = roundOneWithoutRepository($this->requester, ['web']);

    expect(ThreadCardsData::needsYouReason($offering, $this->requester))->toBe('repository_question');
});

it('masks the early warning pick and the co-owner decision for a viewer who cannot drive', function () {
    $owner = User::factory()->create();
    $task = YakTask::factory()->create(['repo' => 'web', 'started_by_user_id' => $this->requester->id, 'early_warning' => ['areas' => [['name' => 'Billing', 'risk' => 'high']], 'state' => 'shown']]);
    $thread = Thread::query()->findOrFail($task->thread_id);
    $thread->forceFill(['visibility' => 'private', 'owner_user_id' => $owner->id])->save();
    ThreadShare::query()->create(['thread_id' => $thread->id, 'user_id' => $this->requester->id]);

    expect(ThreadCardsData::for($thread->fresh(), $this->requester)['earlyWarning']['canPick'])->toBeFalse();

    $held = YakTask::factory()->awaitingCoOwner()->create(['started_by_user_id' => $this->requester->id]);
    CoOwnerRequest::factory()->create(['task_id' => $held->id]);
    $heldThread = Thread::query()->findOrFail($held->thread_id);

    expect(ThreadCardsData::for($heldThread, $this->requester)['coOwner']['canDecide'])->toBeTrue();

    $heldThread->forceFill(['visibility' => 'private', 'owner_user_id' => $owner->id])->save();
    ThreadShare::query()->create(['thread_id' => $heldThread->id, 'user_id' => $this->requester->id]);

    expect(ThreadCardsData::for($heldThread->fresh(), $this->requester)['coOwner']['canDecide'])->toBeFalse();
});

it('refuses a message from a person who cannot drive the thread, including the stopped reply path, creating nothing', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $task = YakTask::factory()->failed()->create(['repo' => 'web', 'pr_url' => null, 'mode' => TaskMode::Fix, 'stop_kind' => StopKind::YakStopped, 'stop_summary' => 'Stopped.', 'started_by_user_id' => $owner->id]);
    $thread = Thread::query()->findOrFail($task->thread_id);
    $thread->forceFill(['visibility' => 'private', 'owner_user_id' => $owner->id])->save();
    ThreadShare::query()->create(['thread_id' => $thread->id, 'user_id' => $viewer->id]);

    expect(fn () => app(ThreadMessageSender::class)->send($thread->fresh(), $viewer, new ThreadMessageInput('hello', null, null, null, MessageDelivery::Steer, null)))
        ->toThrow(ThreadActionRefused::class, 'You cannot send messages to this thread.');

    $this->withHeaders(phoneHeadersFor($viewer))->postJson(appThreadPath($thread, '/messages'), ['message' => 'hello', 'send_key' => '6f1d7c0e-5b7a-4a52-9c1e-0d2f3a4b5c6d'])->assertForbidden();

    expect(PendingSteeringMessage::query()->count())->toBe(0)->and($task->fresh()->status)->toBe(TaskStatus::Failed);
    Queue::assertNothingPushed();
});

it('keeps the starter of a stopped task and passes the author to the note', function () {
    $other = User::factory()->create();
    $task = YakTask::factory()->failed()->create(['repo' => 'web', 'pr_url' => null, 'stop_kind' => StopKind::YakStopped, 'stop_summary' => 'Stopped.', 'started_by_user_id' => $this->requester->id]);
    $thread = Thread::query()->findOrFail($task->thread_id);

    $this->mock(StoppedTaskReplies::class)->shouldReceive('handle')->once()
        ->withArgs(fn (YakTask $head, string $message, string $source, ?string $authorName, $afterNoteQueued, ?int $authorUserId) => $head->is($task) && $authorUserId === $other->id)
        ->andReturn('Trying again with your note.');

    $this->actingAs($other)->post(route('threads.messages.store', $thread), ['message' => 'use the helper'])->assertRedirect();

    expect($task->fresh()->started_by_user_id)->toBe($this->requester->id);
});

it('answers a JSON request on a thread route and on an app API route with the JSON refusal', function () {
    Route::middleware('web')->post('/round-one/threads', fn () => throw ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, 'Closed.'))->name('threads.round-one');

    $this->actingAs($this->requester)->postJson('/round-one/threads')->assertStatus(409)->assertJsonPath('message', 'Closed.');

    $owner = User::factory()->create();
    $thread = Thread::factory()->create(['status' => 'closed', 'closed_at' => now(), 'close_reason' => 'merged', 'owner_user_id' => $owner->id, 'created_by_user_id' => $owner->id]);
    YakTask::factory()->create(['thread_id' => $thread->id]);

    $this->withHeaders(phoneHeadersFor($owner))->postJson(appThreadPath($thread, '/messages'), ['message' => 'hi', 'send_key' => '6f1d7c0e-5b7a-4a52-9c1e-0d2f3a4b5c6e'])
        ->assertStatus(409)->assertJsonPath('error', ThreadActionRefused::THREAD_CLOSED);
});
