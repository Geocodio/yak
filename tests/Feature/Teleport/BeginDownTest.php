<?php

use App\Enums\TeleportDirection;
use App\Enums\TeleportState;
use App\Enums\TurnState;
use App\Jobs\PrepareTeleportDownJob;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'acme/widgets', 'github_full_name' => 'acme/widgets']);
    [$this->device, $this->token] = makeDevice();
    $this->thread = Thread::factory()->create(['repo' => 'acme/widgets', 'branch' => 'yak/fix-it']);
});

function beginDown(object $test, array $overrides = [], ?string $token = null): TestResponse
{
    return $test->postJson('/api/cli/v1/teleports', [
        'direction' => 'down',
        'thread_id' => $test->thread->id,
        'local_path' => '/Users/ada/widgets',
        'os' => 'darwin',
        'arch' => 'arm64',
        ...$overrides,
    ], cliHeaders($token ?? $test->token));
}

function runningTurnOn(Thread $thread): TaskRun
{
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);

    return TaskRun::factory()->create(['thread_id' => $thread->id, 'yak_task_id' => $task->id, 'turn_state' => TurnState::Running]);
}

it('starts preparing on an idle thread and dispatches the job', function () {
    $response = beginDown($this, ['visibility' => 'keep'])->assertOk();

    $teleport = Teleport::query()->findOrFail($response->json('teleport_id'));
    expect($teleport->direction)->toBe(TeleportDirection::Down)
        ->and($teleport->state)->toBe(TeleportState::Preparing)
        ->and($teleport->visibility_choice)->toBe('keep');
    $response->assertJsonPath('resumed', false)->assertJsonPath('teleport.state', 'preparing')->assertJsonPath('teleport.poll_after_seconds', 2);
    Queue::assertPushed(PrepareTeleportDownJob::class, fn (PrepareTeleportDownJob $job): bool => $job->teleportId === $teleport->id);
});

it('answers turn_running when a turn runs and no choice was given', function () {
    $run = runningTurnOn($this->thread);

    beginDown($this, ['visibility' => 'keep'])->assertStatus(409)
        ->assertJsonPath('error', 'turn_running')
        ->assertJsonPath('details.turn_started_at', $run->started_at->utc()->format('Y-m-d\TH:i:s\Z'));
    expect(Teleport::query()->count())->toBe(0);
});

it('sets waiting_for_turn when the person chose to wait', function () {
    runningTurnOn($this->thread);

    $response = beginDown($this, ['running_turn' => 'wait', 'visibility' => 'keep'])->assertOk()->assertJsonPath('teleport.state', 'waiting_for_turn');
    expect(Teleport::query()->find($response->json('teleport_id')))->running_turn->toBe('wait');
    Queue::assertPushed(PrepareTeleportDownJob::class);
});

it('dispatches the job in preparing state when the person chose stop', function () {
    runningTurnOn($this->thread);

    beginDown($this, ['running_turn' => 'stop', 'visibility' => 'keep'])->assertOk()->assertJsonPath('teleport.state', 'preparing');
    Queue::assertPushed(PrepareTeleportDownJob::class);
});

it('needs a visibility choice for a team thread', function () {
    beginDown($this)->assertStatus(409)->assertJsonPath('error', 'visibility_choice_required')->assertJsonPath('details.current', 'team');
});

it('ignores visibility for a private thread', function () {
    $thread = Thread::factory()->private($this->device->user)->create(['repo' => 'acme/widgets']);
    $this->thread = $thread;

    $response = beginDown($this, ['visibility' => 'keep'])->assertOk();
    expect(Teleport::query()->find($response->json('teleport_id')))->visibility_choice->toBeNull();
});

it('rejects the wrong repo', function () {
    Repository::factory()->create(['slug' => 'acme/other', 'github_full_name' => 'acme/other']);

    beginDown($this, ['visibility' => 'keep', 'repos' => [['repo' => 'acme/other', 'role' => 'primary']]])->assertStatus(422)
        ->assertJsonPath('error', 'wrong_repo')
        ->assertJsonPath('details.thread_repo', 'acme/widgets')
        ->assertJsonPath('details.given_repo', 'acme/other');
});

it('rejects a thread another device owns', function () {
    [$other] = makeDevice($this->device->user);
    $this->thread->update(['owner_side' => 'device', 'owner_device_id' => $other->id]);

    beginDown($this, ['visibility' => 'keep'])->assertStatus(409)
        ->assertJsonPath('error', 'thread_owned_by_device')->assertJsonPath('details.device_name', $other->name);
});

it('rejects a thread this device already owns', function () {
    $this->thread->update(['owner_side' => 'device', 'owner_device_id' => $this->device->id]);

    beginDown($this, ['visibility' => 'keep'])->assertStatus(409)->assertJsonPath('error', 'already_on_this_device');
});

it('returns the completed teleport again when this device repeats a finished down', function () {
    $teleport = Teleport::factory()->down()->inState(TeleportState::Completed)->create([
        'thread_id' => $this->thread->id, 'device_id' => $this->device->id, 'completed_at' => now(),
    ]);
    $this->thread->update(['owner_side' => 'device', 'owner_device_id' => $this->device->id]);

    beginDown($this, ['visibility' => 'keep'])->assertOk()
        ->assertJsonPath('teleport_id', $teleport->id)->assertJsonPath('resumed', true)->assertJsonPath('teleport.state', 'completed');
    Queue::assertNothingPushed();
});

it('resumes an open down teleport of the same device', function () {
    $first = beginDown($this, ['visibility' => 'keep'])->assertOk();
    $second = beginDown($this, ['visibility' => 'keep'])->assertOk();

    expect($second->json('teleport_id'))->toBe($first->json('teleport_id'))->and($second->json('resumed'))->toBeTrue();
    expect(Teleport::query()->count())->toBe(1);
    Queue::assertPushed(PrepareTeleportDownJob::class, 1);
});

it('refuses a second device while a teleport is open', function () {
    [, $otherToken] = makeDevice($this->device->user);
    beginDown($this, ['visibility' => 'keep'])->assertOk();

    beginDown($this, ['visibility' => 'keep'], $otherToken)->assertStatus(409)->assertJsonPath('error', 'teleport_in_progress');
});

it('answers 404 for a private thread the caller may not see', function () {
    $this->thread = Thread::factory()->private(User::factory()->create())->create(['repo' => 'acme/widgets']);

    beginDown($this, ['visibility' => 'keep'])->assertStatus(404)->assertJsonPath('error', 'thread_not_found');
});

it('answers 404 for a missing thread', function () {
    beginDown($this, ['thread_id' => 999999, 'visibility' => 'keep'])->assertStatus(404)->assertJsonPath('error', 'thread_not_found');
});
