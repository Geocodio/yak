<?php

use App\DataTransferObjects\ClarificationQuestion;
use App\Enums\CoOwnerRequestStatus;
use App\Enums\StopKind;
use App\Enums\TaskStatus;
use App\Enums\ThreadSandboxState;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadFactory;
use Carbon\CarbonImmutable;
use Tests\Support\ContractFixture;

const CARD_SHOW_VOLATILE = [
    'thread.id', 'thread.rootThreadId', 'thread.preview.targets.*.threadId', 'messages.*.id', 'messages.*.uuid', 'question.taskId', 'teleport.command', 'teleport.link',
    'coOwner.id', 'coOwner.connectUrl',
];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    $this->user = User::factory()->create(['name' => 'Test person']);
    $this->headers = phoneHeadersFor($this->user);
    Repository::factory()->create(['slug' => 'example/api', 'is_active' => true]);
    Repository::factory()->create(['slug' => 'example/web', 'is_active' => true]);
});

function cardThread(User $user, array $state = []): Thread
{
    return Thread::factory()->create([
        'title' => 'Retry failed uploads', 'name' => 'retry-failed-uploads', 'repo' => 'example/api', 'created_by_user_id' => $user->id, 'owner_user_id' => $user->id,
        'event_sequence' => 12, 'sandbox_state' => ThreadSandboxState::None, ...$state,
    ]);
}

it('says whether the thread has a repository on the show and on the rows', function () {
    $with = cardThread($this->user);
    $without = cardThread($this->user, ['title' => 'Which repo', 'repo' => Thread::UNKNOWN_REPOSITORY]);

    $this->getJson(appThreadPath($with), $this->headers)->assertOk()->assertJsonPath('thread.hasRepository', true)->assertJsonPath('thread.repo', 'example/api');
    $this->getJson(appThreadPath($without), $this->headers)->assertOk()->assertJsonPath('thread.hasRepository', false)->assertJsonPath('thread.repo', 'unknown');

    $rows = collect($this->getJson('/api/app/v1/threads', $this->headers)->assertOk()->json('threads'))->keyBy('id');
    expect($rows[$with->public_id]['hasRepository'])->toBeTrue()
        ->and($rows[$without->public_id]['hasRepository'])->toBeFalse()
        ->and($rows[$without->public_id]['repo'])->toBe('unknown');
});

it('adds the thread id to a task item that has no open thread and lists a task with an open thread once', function () {
    $closed = cardThread($this->user, ['status' => 'closed', 'closed_at' => now(), 'close_reason' => 'merged']);
    $closedTask = YakTask::factory()->awaitingClarification()->create(['thread_id' => $closed->id, 'started_by_user_id' => $this->user->id]);
    $loose = YakTask::factory()->awaitingClarification()->create(['thread_id' => null, 'started_by_user_id' => $this->user->id]);
    $open = cardThread($this->user, ['title' => 'Open question']);
    $openTask = YakTask::factory()->awaitingClarification()->create(['thread_id' => $open->id, 'started_by_user_id' => $this->user->id]);

    $needsYou = collect($this->getJson('/api/app/v1/threads', $this->headers)->assertOk()->json('needsYou'));

    $items = $needsYou->where('type', 'task')->keyBy('taskId');
    expect($items[$closedTask->public_id]['threadId'])->toBe($closed->public_id)
        ->and($items[$loose->public_id]['threadId'])->toBeNull()
        ->and($items->has($openTask->public_id))->toBeFalse()
        ->and($needsYou->where('type', 'thread')->where('id', $open->public_id)->count())->toBe(1)
        ->and($needsYou->count())->toBe(3);
});

it('names no thread on a task item for a viewer who cannot see it', function () {
    $other = User::factory()->create();
    $hidden = cardThread($other, ['visibility' => 'private', 'status' => 'closed', 'closed_at' => now(), 'close_reason' => 'merged']);
    YakTask::factory()->awaitingClarification()->create(['thread_id' => $hidden->id, 'started_by_user_id' => $this->user->id]);

    $json = $this->getJson('/api/app/v1/threads', $this->headers)->assertOk()->json();

    expect(json_encode($json))->not->toContain($hidden->public_id);
});

it('shows the repository question', function () {
    $task = YakTask::factory()->create(['repo' => 'unknown', 'source' => 'slack', 'description' => 'Retry failed uploads', 'status' => TaskStatus::AwaitingClarification, 'started_by_user_id' => $this->user->id, 'clarification_options' => ['example/api', 'example/web'], 'session_id' => null]);
    $thread = app(ThreadFactory::class)->forRequestWithoutRepository($task);
    $thread->update(['title' => 'Retry failed uploads', 'name' => 'retry-failed-uploads', 'event_sequence' => 12, 'sandbox_state' => ThreadSandboxState::None]);

    $response = $this->getJson(appThreadPath($thread), $this->headers)->assertOk()
        ->assertJsonPath('card', 'repository_question')
        ->assertJsonPath('repositoryQuestion.options', ['example/api', 'example/web'])
        ->assertJsonPath('thread.hasRepository', false);
    ContractFixture::assertMatches('app', 'thread-repository-question', $response->json(), [...CARD_SHOW_VOLATILE, 'request.starter.key']);
});

it('shows a waiting co-owner request', function () {
    $repository = Repository::factory()->create(['slug' => 'example-api', 'github_full_name' => 'example/api']);
    $coOwner = User::factory()->withGitHub()->create(['name' => 'Second person']);
    $thread = cardThread($this->user, ['repo' => 'example-api']);
    $task = YakTask::factory()->awaitingCoOwner()->create(['thread_id' => $thread->id, 'repo' => 'example-api', 'branch_name' => 'yak/retry-failed-uploads', 'description' => 'Retry failed uploads', 'source' => 'slack', 'started_by_user_id' => $this->user->id]);
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'repository_id' => $repository->id, 'asked_user_ids' => [$coOwner->id], 'status' => CoOwnerRequestStatus::Pending]);

    $response = $this->getJson(appThreadPath($thread), $this->headers)->assertOk()
        ->assertJsonPath('card', 'co_owner')->assertJsonPath('coOwner.canDecide', true)->assertJsonPath('coOwner.viewerIsAsked', false);
    ContractFixture::assertMatches('app', 'thread-co-owner', $response->json(), [...CARD_SHOW_VOLATILE, 'request.starter.key']);
});

it('shows a stopped thread', function () {
    $thread = cardThread($this->user);
    YakTask::factory()->failed()->create(['thread_id' => $thread->id, 'repo' => 'example/api', 'description' => 'Retry failed uploads', 'source' => 'dashboard', 'started_by_user_id' => $this->user->id, 'stop_kind' => StopKind::YakStopped, 'stop_summary' => 'Tests kept failing.', 'error_log' => 'The test suite failed three times.']);

    $response = $this->getJson(appThreadPath($thread), $this->headers)->assertOk()
        ->assertJsonPath('card', 'stopped')->assertJsonPath('stopped.kind', 'yak_stopped')->assertJsonPath('actions.canRetry', true);
    ContractFixture::assertMatches('app', 'thread-stopped', $response->json(), [...CARD_SHOW_VOLATILE, 'request.starter.key']);
});

it('shows a finished thread with no progress', function () {
    $thread = cardThread($this->user);
    YakTask::factory()->create(['thread_id' => $thread->id, 'repo' => 'example/api', 'description' => 'Retry failed uploads', 'source' => 'dashboard', 'status' => TaskStatus::Success, 'started_by_user_id' => $this->user->id]);

    $response = $this->getJson(appThreadPath($thread), $this->headers)->assertOk()
        ->assertJsonPath('progress', null)->assertJsonPath('card', null)->assertJsonPath('request.status.key', 'done');
    ContractFixture::assertMatches('app', 'thread-finished', $response->json(), [...CARD_SHOW_VOLATILE, 'request.starter.key']);
});

it('keeps the question card key for a thread with questions', function () {
    $thread = cardThread($this->user);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'status' => TaskStatus::AwaitingClarification, 'started_by_user_id' => $this->user->id, 'clarification_expires_at' => now()->addDay()]);
    $task->recordClarificationRound([ClarificationQuestion::fromArray([
        'id' => 'scope', 'header' => 'Scope', 'question' => 'Which scope?', 'options' => [['label' => 'Small', 'description' => 's'], ['label' => 'Large', 'description' => 'l']], 'multi_select' => false,
    ])], 'One decision.');

    $this->getJson(appThreadPath($thread), $this->headers)->assertOk()->assertJsonPath('card', 'question');
});
