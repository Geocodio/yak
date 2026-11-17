<?php

use App\DataTransferObjects\ClarificationQuestion;
use App\Enums\CoOwnerRequestStatus;
use App\Enums\StopKind;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Enums\ThreadBlockReason;
use App\Http\Resources\TaskDetailData;
use App\Http\Resources\ThreadCardsData;
use App\Http\Resources\ThreadNoticeData;
use App\Http\Resources\ThreadWalkthroughData;
use App\Jobs\RunYakJob;
use App\Models\Artifact;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Models\VideoMetric;
use App\Models\YakTask;
use App\Services\Threads\ThreadFactory;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    $this->web = Repository::factory()->create(['slug' => 'web', 'is_active' => true]);
    Repository::factory()->create(['slug' => 'api', 'is_active' => true]);
    $this->requester = User::factory()->create();
});

function cardsThread(YakTask $task): Thread
{
    return Thread::query()->findOrFail($task->thread_id);
}

function cardsFor(Thread $thread, User $viewer): array
{
    return ThreadCardsData::for($thread, $viewer);
}

function repositoryQuestionThread(User $requester): array
{
    $task = YakTask::factory()->create([
        'repo' => 'unknown',
        'status' => TaskStatus::AwaitingClarification,
        'started_by_user_id' => $requester->id,
        'clarification_options' => ['web', 'api'],
    ]);

    return [app(ThreadFactory::class)->forRequestWithoutRepository($task), $task];
}

it('shows the repository question to a thread without a repository', function () {
    [$thread] = repositoryQuestionThread($this->requester);

    $cards = cardsFor($thread, $this->requester);

    expect($cards['card'])->toBe('repository_question')
        ->and($cards['repositoryQuestion'])->toBe(['prompt' => 'Which repository is this for?', 'options' => ['web', 'api'], 'selected' => null, 'canAnswer' => true]);
});

it('preselects the repository named by ?repo= when it is one of the options', function () {
    [$thread] = repositoryQuestionThread($this->requester);

    $this->actingAs($this->requester)->get("/threads/{$thread->public_id}?repo=api")->assertInertia(fn (Assert $page) => $page
        ->where('card', 'repository_question')
        ->where('repositoryQuestion.selected', 'api'));
    $this->get("/threads/{$thread->public_id}?repo=nope")->assertInertia(fn (Assert $page) => $page->where('repositoryQuestion.selected', null));
});

it('shows the question form before anything else', function () {
    [$thread, $task] = repositoryQuestionThread($this->requester);
    $task->recordClarificationRound([ClarificationQuestion::fromArray([
        'id' => 'on_failure', 'header' => 'On failure', 'question' => 'What now?',
        'options' => [['label' => 'A', 'description' => 'a'], ['label' => 'B', 'description' => 'b']], 'multi_select' => false,
    ])], 'One decision.');

    expect(cardsFor($thread, $this->requester)['card'])->toBe('question');
});

it('shows the early warning card to the requester only', function () {
    $task = YakTask::factory()->create(['repo' => 'web', 'started_by_user_id' => $this->requester->id, 'early_warning' => ['areas' => [['name' => 'Billing', 'risk' => 'high']], 'state' => 'shown']]);
    $thread = cardsThread($task);

    $own = cardsFor($thread, $this->requester);

    expect($own['card'])->toBe('early_warning')
        ->and($own['earlyWarning']['canPick'])->toBeTrue()
        ->and(cardsFor($thread, User::factory()->create())['card'])->toBeNull();
});

it('shows the co-owner card for a pending request with the panel for the viewer', function () {
    $coOwner = User::factory()->withGitHub()->create();
    $task = YakTask::factory()->awaitingCoOwner()->create(['repo' => 'web', 'started_by_user_id' => $this->requester->id]);
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'repository_id' => $this->web->id, 'asked_user_ids' => [$coOwner->id]]);
    $thread = cardsThread($task);

    $cards = cardsFor($thread, $this->requester);

    expect($cards['card'])->toBe('co_owner')
        ->and($cards['coOwner']['viewer']['isRequester'])->toBeTrue()
        ->and($cards['coOwner']['asked'])->toBe([$coOwner->name])
        ->and($cards['coOwner']['connectUrl'])->toContain(urlencode("/threads/{$thread->public_id}"));
});

it('keeps a decided co-owner request out of the card slot', function () {
    $task = YakTask::factory()->create(['repo' => 'web', 'started_by_user_id' => $this->requester->id]);
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'repository_id' => $this->web->id, 'status' => CoOwnerRequestStatus::Approved]);

    $cards = cardsFor(cardsThread($task), $this->requester);

    expect($cards['card'])->toBeNull()->and($cards['coOwner']['status'])->toBe('approved');
});

it('shows the stop card for a failed task with a stop block and for a cancelled one', function () {
    $failed = YakTask::factory()->failed()->create(['repo' => 'web', 'stop_kind' => StopKind::YakStopped, 'stop_summary' => 'Tests kept failing.']);
    $cancelled = YakTask::factory()->create(['repo' => 'web', 'status' => TaskStatus::Cancelled]);

    $stopped = cardsFor(cardsThread($failed), $this->requester);
    $cancelledCards = cardsFor(cardsThread($cancelled), $this->requester);

    expect($stopped['card'])->toBe('stopped')
        ->and($stopped['stopped'])->toMatchArray(['kind' => 'yak_stopped', 'summary' => 'Tests kept failing.'])
        ->and($cancelledCards['card'])->toBe('stopped')
        ->and($cancelledCards['stopped']['kind'])->toBe('cancelled');
});

it('gives the card by priority when two apply', function () {
    $coOwner = User::factory()->withGitHub()->create();
    $task = YakTask::factory()->failed()->create(['repo' => 'web', 'stop_kind' => StopKind::YakStopped, 'stop_summary' => 'Stopped.', 'started_by_user_id' => $this->requester->id, 'early_warning' => ['areas' => [['name' => 'Billing', 'risk' => 'high']], 'state' => 'shown']]);
    $thread = cardsThread($task);

    expect(cardsFor($thread, $this->requester)['card'])->toBe('early_warning');

    $task->update(['early_warning' => null]);
    $request = CoOwnerRequest::factory()->create(['task_id' => $task->id, 'repository_id' => $this->web->id, 'asked_user_ids' => [$coOwner->id]]);

    expect(cardsFor($thread, $this->requester)['card'])->toBe('co_owner');

    $request->update(['status' => CoOwnerRequestStatus::Withdrawn]);

    expect(cardsFor($thread, $this->requester)['card'])->toBe('stopped');
});

it('computes the action flags like the task page for the same task', function () {
    $task = YakTask::factory()->failed()->create(['repo' => 'web', 'pr_url' => null]);
    $thread = cardsThread($task);

    $expected = TaskDetailData::actions($task, $task, null, false);
    $actions = cardsFor($thread, $this->requester)['actions'];

    expect($actions)->toMatchArray([
        'canRetry' => $expected['canRetry'],
        'canCancel' => $expected['canCancel'],
        'canMove' => $expected['canReroute'],
        'moveTargets' => $expected['rerouteTargets'],
        'canRequestReview' => false,
        'canRetryRender' => false,
        'canStop' => false,
    ])->and($actions['canRetry'])->toBeTrue()->and($actions['moveTargets'])->toBe(['api']);
});

it('shows a viewer who cannot drive the card with no actions', function () {
    $task = YakTask::factory()->failed()->create(['repo' => 'web', 'stop_kind' => StopKind::YakStopped, 'stop_summary' => 'Stopped.']);
    $thread = cardsThread($task);
    $thread->forceFill(['visibility' => 'private', 'owner_user_id' => User::factory()->create()->id])->save();
    $viewer = User::factory()->create();
    ThreadShare::query()->create(['thread_id' => $thread->id, 'user_id' => $viewer->id]);

    $cards = cardsFor($thread->fresh(), $viewer);

    expect($cards['card'])->toBe('stopped')
        ->and(array_filter($cards['actions']))->toBe([]);
});

it('gives no card and no actions on a closed, a moved or a legacy thread', function (array $state) {
    $task = YakTask::factory()->failed()->create(['repo' => 'web', 'stop_kind' => StopKind::YakStopped, 'stop_summary' => 'Stopped.']);
    $thread = cardsThread($task);
    $thread->update($state);

    $cards = cardsFor($thread->fresh(), $this->requester);

    expect($cards['card'])->toBeNull()->and($cards['stopped'])->toBeNull()->and(array_filter($cards['actions']))->toBe([]);
})->with([
    'closed' => [['status' => 'closed', 'closed_at' => now(), 'close_reason' => 'merged']],
    'moved' => [['status' => 'closed', 'closed_at' => now(), 'close_reason' => 'moved']],
    'legacy' => [['is_legacy' => true]],
]);

it('builds the same keys into the thread page props', function () {
    $task = YakTask::factory()->failed()->create(['repo' => 'web', 'stop_kind' => StopKind::YakStopped, 'stop_summary' => 'Stopped.']);

    $this->actingAs($this->requester)->get('/threads/' . cardsThread($task)->public_id)->assertInertia(fn (Assert $page) => $page
        ->where('card', 'stopped')
        ->where('stopped.summary', 'Stopped.')
        ->where('actions.canRetry', true)
        ->has('repositoryQuestion')->has('earlyWarning')->has('coOwner'));
});

it('attaches the chosen repository and starts the run', function () {
    [$thread, $task] = repositoryQuestionThread($this->requester);

    $this->actingAs($this->requester)->post(route('threads.repository-answer.store', $thread), ['repo' => 'api'])
        ->assertRedirect(route('threads.show', $thread))
        ->assertSessionHas('success', 'Starting in api.');

    expect($thread->fresh()->repo)->toBe('api')
        ->and($task->fresh()->repo)->toBe('api')
        ->and($task->fresh()->status)->toBe(TaskStatus::Pending);
    Queue::assertPushed(RunYakJob::class);
});

it('refuses a repository Yak did not offer and a thread that already has one', function () {
    [$thread, $task] = repositoryQuestionThread($this->requester);

    $this->actingAs($this->requester)->post(route('threads.repository-answer.store', $thread), ['repo' => 'elsewhere'])
        ->assertSessionHas('error', 'Pick one of the repositories Yak offered.');
    $this->post(route('threads.repository-answer.store', $thread))->assertSessionHasErrors('repo');
    expect($thread->fresh()->hasRepository())->toBeFalse();

    $this->post(route('threads.repository-answer.store', $thread), ['repo' => 'web']);
    $this->post(route('threads.repository-answer.store', $thread->fresh()), ['repo' => 'api'])
        ->assertSessionHas('error', 'This thread already has a repository.');
    expect($thread->fresh()->repo)->toBe('web');
});

it('refuses a viewer who cannot drive the thread and hides it from a stranger', function () {
    [$thread] = repositoryQuestionThread($this->requester);
    $thread->forceFill(['visibility' => 'private', 'owner_user_id' => $this->requester->id])->save();
    $viewer = User::factory()->create();
    ThreadShare::query()->create(['thread_id' => $thread->id, 'user_id' => $viewer->id]);

    $this->actingAs($viewer)->post(route('threads.repository-answer.store', $thread), ['repo' => 'api'])->assertForbidden();
    $this->actingAs(User::factory()->create())->post(route('threads.repository-answer.store', $thread), ['repo' => 'api'])->assertNotFound();

    expect($thread->fresh()->hasRepository())->toBeFalse();
    Queue::assertNothingPushed();
});

it('shows a failed walkthrough render with its error and the retry flag', function () {
    $task = YakTask::factory()->create(['repo' => 'web']);
    $thread = cardsThread($task);
    Artifact::factory()->video()->create(['yak_task_id' => $task->id]);
    VideoMetric::factory()->failed()->create(['yak_task_id' => $task->id, 'error' => 'ffmpeg exited with 1']);

    $walkthrough = ThreadWalkthroughData::for($thread);

    expect($walkthrough)->toMatchArray(['isRenderFailed' => true, 'renderError' => 'ffmpeg exited with 1', 'canRetryRender' => true, 'video' => null]);
});

it('offers continuing after setup and the failed setup run on the repository notice', function () {
    $setupTask = YakTask::factory()->create(['repo' => 'web', 'mode' => TaskMode::Setup]);
    $repository = Repository::factory()->create(['slug' => 'fresh', 'is_active' => true, 'setup_status' => 'failed', 'setup_task_id' => $setupTask->id, 'sandbox_snapshot' => null]);
    $task = YakTask::factory()->failed()->create(['repo' => $repository->slug, 'started_by_user_id' => $this->requester->id]);
    $thread = cardsThread($task);
    $thread->forceFill(['blocked_reason' => ThreadBlockReason::RepositorySetupFailed])->save();

    $notice = ThreadNoticeData::for($thread->fresh(), $this->requester);

    expect($notice)->toMatchArray(['kind' => 'repository_setup_failed', 'canContinueAfterSetup' => true, 'failedSetupRunUrl' => route('repos.runs.show', [$repository, $setupTask])]);

    $task->update(['resume_after_setup_at' => now()]);

    expect(ThreadNoticeData::for($thread->fresh(), $this->requester)['canContinueAfterSetup'])->toBeFalse();
});

it('sends a thread message to a stopped head task as a note and retries it', function () {
    $task = YakTask::factory()->failed()->create(['repo' => 'web', 'stop_kind' => StopKind::YakStopped, 'stop_summary' => 'Stopped.', 'started_by_user_id' => $this->requester->id]);
    $thread = cardsThread($task);

    $this->actingAs($this->requester)->get("/threads/{$thread->public_id}")->assertInertia(fn (Assert $page) => $page->where('composer.state', 'stopped'));

    $this->post(route('threads.messages.store', $thread), ['message' => 'use the helper'])
        ->assertRedirect()
        ->assertSessionHas('success', 'Trying again with your note.');

    expect($task->fresh()->status)->toBe(TaskStatus::Pending)
        ->and($task->fresh()->description)->toContain('use the helper');
    Queue::assertPushed(RunYakJob::class, 1);
});

it('keeps a thread with a pull request out of the stopped composer', function () {
    $task = YakTask::factory()->failed()->create(['repo' => 'web', 'pr_url' => 'https://github.com/o/r/pull/9']);

    $this->actingAs($this->requester)->get('/threads/' . cardsThread($task)->public_id)->assertInertia(fn (Assert $page) => $page->where('composer.state', 'idle'));
});
