<?php

use App\Enums\CoOwnerRequestStatus;
use App\Http\Resources\ThreadCardsData;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadFactory;
use Illuminate\Support\Facades\DB;

function needsYouThread(User $requester, array $attributes = []): Thread
{
    $task = YakTask::factory()->create(['started_by_user_id' => $requester->id, ...$attributes]);

    return Thread::query()->findOrFail($task->thread_id);
}

function heldThreadFor(User $requester, array $askedUserIds, CoOwnerRequestStatus $status = CoOwnerRequestStatus::Pending): Thread
{
    $task = YakTask::factory()->awaitingCoOwner()->create(['started_by_user_id' => $requester->id]);
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'asked_user_ids' => $askedUserIds, 'status' => $status]);

    return Thread::query()->findOrFail($task->thread_id);
}

function threadWithoutRepositoryFor(User $requester): Thread
{
    $task = YakTask::factory()->create(['repo' => 'unknown', 'started_by_user_id' => $requester->id]);

    return app(ThreadFactory::class)->forRequestWithoutRepository($task);
}

beforeEach(function () {
    Repository::factory()->create(['slug' => 'web', 'is_active' => true]);
    $this->requester = User::factory()->create();
    $this->coOwner = User::factory()->create();
    $this->other = User::factory()->create();
});

it('says co_owner for a person who was asked and for nobody else', function () {
    $thread = heldThreadFor($this->requester, [$this->coOwner->id]);

    expect(ThreadCardsData::needsYouReason($thread, $this->coOwner))->toBe('co_owner')
        ->and(ThreadCardsData::needsYouReason($thread, $this->other))->toBeNull()
        ->and(ThreadCardsData::needsYouReason($thread, $this->requester))->toBeNull();
});

it('stops saying co_owner once the request is decided', function () {
    $thread = heldThreadFor($this->requester, [$this->coOwner->id], CoOwnerRequestStatus::Approved);

    expect(ThreadCardsData::needsYouReason($thread, $this->coOwner))->toBeNull();
});

it('says early_warning to the requester while the warning is shown', function () {
    $thread = needsYouThread($this->requester, ['early_warning' => ['areas' => [['name' => 'Billing', 'risk' => 'high']], 'state' => 'shown']]);

    expect(ThreadCardsData::needsYouReason($thread, $this->requester))->toBe('early_warning')
        ->and(ThreadCardsData::needsYouReason($thread, $this->other))->toBeNull();
});

it('does not say early_warning once the requester tagged or declined', function (string $state) {
    $thread = needsYouThread($this->requester, ['early_warning' => ['areas' => [['name' => 'Billing', 'risk' => 'high']], 'state' => $state], 'tagged_co_owner_user_id' => $this->coOwner->id]);

    expect(ThreadCardsData::needsYouReason($thread, $this->requester))->toBeNull();
})->with(['tagged', 'declined']);

it('says repository_question to the requester of a thread without a repository', function () {
    $thread = threadWithoutRepositoryFor($this->requester);

    expect(ThreadCardsData::needsYouReason($thread, $this->requester))->toBe('repository_question')
        ->and(ThreadCardsData::needsYouReason($thread, $this->other))->toBeNull();
});

it('says repository_question to a person who can drive a thread that has no requester', function () {
    $task = YakTask::factory()->create(['repo' => 'unknown', 'started_by_user_id' => null, 'slack_user_id' => null]);
    $thread = app(ThreadFactory::class)->forRequestWithoutRepository($task);
    $thread->forceFill(['created_by_user_id' => null])->save();

    expect(ThreadCardsData::needsYouReason($thread->fresh(), $this->other))->toBe('repository_question');
});

it('gives no reason for a closed thread', function () {
    $thread = threadWithoutRepositoryFor($this->requester);
    $thread->forceFill(['status' => 'closed'])->save();

    expect(ThreadCardsData::needsYouReason($thread->fresh(), $this->requester))->toBeNull();
});

it('prefers the co-owner decision over a repository question', function () {
    $thread = heldThreadFor($this->requester, [$this->requester->id]);

    expect(ThreadCardsData::needsYouReason($thread, $this->requester))->toBe('co_owner');
});

it('answers one thread in at most two queries', function () {
    $thread = heldThreadFor($this->requester, [$this->coOwner->id]);

    DB::enableQueryLog();
    ThreadCardsData::needsYouReason($thread, $this->coOwner);

    expect(DB::getQueryLog())->toHaveCount(2);
});

it('answers many threads in the same two queries, keyed by thread id', function () {
    $held = heldThreadFor($this->requester, [$this->coOwner->id]);
    $unknown = threadWithoutRepositoryFor($this->coOwner);
    $warned = needsYouThread($this->coOwner, ['early_warning' => ['areas' => [['name' => 'Billing', 'risk' => 'high']], 'state' => 'shown']]);
    $quiet = needsYouThread($this->coOwner);
    $more = collect(range(1, 5))->map(fn () => needsYouThread($this->coOwner));
    $threads = collect([$held, $unknown, $warned, $quiet])->concat($more);

    DB::enableQueryLog();
    $reasons = ThreadCardsData::needsYouReasons($threads, $this->coOwner);

    expect(count(DB::getQueryLog()))->toBe(3)
        ->and($reasons[$held->id])->toBe('co_owner')
        ->and($reasons[$unknown->id])->toBe('repository_question')
        ->and($reasons[$warned->id])->toBe('early_warning')
        ->and($reasons[$quiet->id])->toBeNull()
        ->and($reasons)->toHaveCount(9);
});
