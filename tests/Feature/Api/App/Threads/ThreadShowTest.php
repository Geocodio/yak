<?php

use App\DataTransferObjects\ClarificationQuestion;
use App\Enums\TaskStatus;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnMessageState;
use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\ThreadTurnMessage;
use App\Models\ThreadView;
use App\Models\User;
use App\Models\YakTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ContractFixture;

const SHOW_VOLATILE = [
    'thread.id', 'thread.rootThreadId', 'thread.preview.targets.*.threadId', 'messages.*.id', 'messages.*.uuid', 'question.taskId', 'teleport.command', 'teleport.link',
];

it('shows a thread with panes, the question and the latest sequence', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    $user = User::factory()->create(['name' => 'Test person']);
    $thread = Thread::factory()->create(['title' => 'Retry failed uploads', 'name' => 'retry-failed-uploads', 'repo' => 'example/api', 'created_by_user_id' => $user->id, 'owner_user_id' => $user->id, 'event_sequence' => 4812, 'sandbox_state' => ThreadSandboxState::None]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'description' => 'Fix the checkout bug', 'status' => TaskStatus::AwaitingClarification, 'started_by_user_id' => $user->id, 'clarification_expires_at' => now()->addDay()]);
    $task->recordClarificationRound([ClarificationQuestion::fromArray([
        'id' => 'on_failure',
        'header' => 'On failure',
        'question' => 'When all retries fail, what should happen to the row?',
        'options' => [
            ['label' => 'Mark the row as failed', 'description' => 'Keep the batch going.'],
            ['label' => 'Fail the whole batch', 'description' => 'Stop at the first failure.'],
        ],
        'multi_select' => false,
    ])], 'I need one decision.');

    $response = $this->getJson(appThreadPath($thread), phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('thread.id', $thread->public_id)
        ->assertJsonPath('panes', ['chat' => true, 'preview' => true, 'terminal' => false, 'files' => false, 'changes' => true])
        ->assertJsonPath('question.taskId', $task->public_id)
        ->assertJsonPath('question.questions.0.multiSelect', false)
        ->assertJsonPath('latestSequence', 4812)
        ->assertJsonStructure(['thread', 'canDrive', 'messages', 'composer' => ['state', 'models'], 'notice']);
    ContractFixture::assertMatches('app', 'thread-question', $response->json(), SHOW_VOLATILE);
});

it('shows a thread after a finished turn', function () {
    fakeApns();
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    $user = User::factory()->create(['name' => 'Test person']);
    $thread = Thread::factory()->create(['title' => 'Retry failed uploads', 'name' => 'retry-failed-uploads', 'repo' => 'example/api', 'created_by_user_id' => $user->id, 'owner_user_id' => $user->id, 'event_sequence' => 12, 'sandbox_state' => ThreadSandboxState::None, 'live_activity_channel' => 'dGVzdC1jaGFubmVs']);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'status' => TaskStatus::Success, 'started_by_user_id' => $user->id]);
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'driver_user_id' => $user->id, 'turn_state' => TurnState::Completed, 'finished_at' => now()->subHour()]);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'state' => TurnMessageState::Completed, 'text' => 'Retry failed uploads with backoff']);

    $response = $this->getJson(appThreadPath($thread), phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('question', null)
        ->assertJsonPath('latestSequence', 12)
        ->assertJsonPath('composer.state', 'idle')
        ->assertJsonPath('messages.0.state', 'read')
        ->assertJsonPath('messages.0.text', 'Retry failed uploads with backoff');
    ContractFixture::assertMatches('app', 'thread', $response->json(), SHOW_VOLATILE);
});

it('shows no channel without a key or for a closed thread', function () {
    $user = User::factory()->create(['name' => 'Test person']);
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id, 'live_activity_channel' => 'dGVzdC1jaGFubmVs']);

    $this->getJson(appThreadPath($thread), phoneHeadersFor($user))->assertOk()->assertJsonPath('liveActivityChannel', null);

    fakeApns();
    $this->getJson(appThreadPath($thread), phoneHeadersFor($user))->assertOk()->assertJsonPath('liveActivityChannel', 'dGVzdC1jaGFubmVs');

    $thread->forceFill(['closed_at' => now()])->save();
    $this->getJson(appThreadPath($thread), phoneHeadersFor($user))->assertOk()->assertJsonPath('liveActivityChannel', null);
});

it('hides the preview pane when the repository has no preview manifest', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'sandbox_state' => ThreadSandboxState::Running]);
    Cache::put("thread-preview:state:{$thread->id}", 'no_manifest');

    $this->getJson(appThreadPath($thread), phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('panes', ['chat' => true, 'preview' => false, 'terminal' => true, 'files' => true, 'changes' => true]);
});

it('shows the terminal and files for a destroyed sandbox', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'sandbox_state' => ThreadSandboxState::Destroyed]);

    $this->getJson(appThreadPath($thread), phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('panes.terminal', true)
        ->assertJsonPath('panes.files', true);
});

it('shows a thread with no tasks and no events', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);

    $this->getJson(appThreadPath($thread), phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('question', null)
        ->assertJsonPath('latestSequence', 0)
        ->assertJsonPath('messages', []);
});

it('answers a hidden thread exactly like a missing one', function () {
    $owner = User::factory()->create();
    $hidden = privateThreadOf($owner);
    $headers = phoneHeadersFor(User::factory()->create());

    $missing = $this->getJson('/api/app/v1/threads/zzzzzzzz', $headers)->assertNotFound();
    $this->getJson(appThreadPath($hidden), $headers)->assertNotFound()->assertExactJson($missing->json());
    $this->getJson("/api/app/v1/threads/{$hidden->id}", $headers)->assertNotFound();

    $missingSeen = $this->postJson('/api/app/v1/threads/zzzzzzzz/seen', [], $headers)->assertNotFound();
    $this->postJson(appThreadPath($hidden, '/seen'), [], $headers)->assertNotFound()->assertExactJson($missingSeen->json());
    $this->postJson("/api/app/v1/threads/{$hidden->id}/seen", [], $headers)->assertNotFound()->assertExactJson($missingSeen->json());
});

it('lets a share recipient read but not drive', function () {
    $owner = User::factory()->create();
    $recipient = User::factory()->create();
    $thread = privateThreadOf($owner);
    ThreadShare::factory()->create(['thread_id' => $thread->id, 'user_id' => $recipient->id]);

    $this->getJson(appThreadPath($thread), phoneHeadersFor($recipient))->assertOk()
        ->assertJsonPath('canDrive', false);
    $this->postJson(appThreadPath($thread, '/seen'), [], phoneHeadersFor($recipient))->assertNoContent();
});

it('marks a thread seen once, answers 204 on a repeat and clears its needs-you row', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'driver_user_id' => $user->id, 'turn_state' => TurnState::Completed, 'finished_at' => now()->subHour()]);
    $headers = phoneHeadersFor($user);

    $before = $this->getJson('/api/app/v1/threads', $headers)->assertOk();
    expect(collect($before->json('needsYou'))->pluck('id')->all())->toContain($thread->public_id)
        ->and($before->json('needsYouCount'))->toBe(1);

    $this->postJson(appThreadPath($thread, '/seen'), [], $headers)->assertNoContent();
    $this->postJson(appThreadPath($thread, '/seen'), [], $headers)->assertNoContent();

    $after = $this->getJson('/api/app/v1/threads', $headers)->assertOk();
    expect(collect($after->json('needsYou'))->pluck('id')->all())->not->toContain($thread->public_id)
        ->and($after->json('needsYouCount'))->toBe(0)
        ->and(ThreadView::query()->where('user_id', $user->id)->where('thread_id', $thread->id)->count())->toBe(1);
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);

    $this->getJson(appThreadPath($thread), ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $user->id]);
    $device->update(['revoked_at' => now()]);
    $this->getJson(appThreadPath($thread), $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $user->id], isPhone: false);
    $this->getJson(appThreadPath($thread), $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
    $this->postJson(appThreadPath($thread, '/seen'), [], $computerHeaders)->assertUnauthorized();
});
