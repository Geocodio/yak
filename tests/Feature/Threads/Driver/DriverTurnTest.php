<?php

use App\DataTransferObjects\TurnTrackerChange;
use App\Enums\TurnMessageState;
use App\Enums\TurnState;
use App\Events\TurnStarted;
use App\Models\PendingSteeringMessage;
use App\Models\ThreadSession;
use App\Models\ThreadTurnMessage;
use App\Models\YakTask;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/../../../Support/ThreadTurnHelpers.php';

function lifecycleLine(string $uuid, string $state): array
{
    return ['type' => 'command_lifecycle', 'uuid' => $uuid, 'state' => $state];
}

it('moves message states and starts the turn when the prompt is read', function () {
    Event::fake([TurnStarted::class]);
    [$thread, $run] = runningTurnFor(['turn_state' => TurnState::WaitingForSandbox, 'prompt_uuid' => 'prompt-1']);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'uuid' => 'prompt-1']);

    pushRunnerLine($thread, 1, lifecycleLine('prompt-1', 'queued'));

    expect(ThreadTurnMessage::query()->where('uuid', 'prompt-1')->value('state'))->toBe(TurnMessageState::Queued)
        ->and($run->refresh()->turn_state)->toBe(TurnState::WaitingForSandbox);

    pushRunnerLine($thread, 2, lifecycleLine('prompt-1', 'started'));
    pushRunnerLine($thread, 3, lifecycleLine('prompt-1', 'started'));

    expect(ThreadTurnMessage::query()->where('uuid', 'prompt-1')->value('state'))->toBe(TurnMessageState::Started)
        ->and($run->refresh()->turn_state)->toBe(TurnState::Running)
        ->and($run->agent_started_at)->not->toBeNull();
    Event::assertDispatchedTimes(TurnStarted::class, 1);
});

it('marks steers delivered then absorbed', function () {
    [$thread, $run] = runningTurnFor();
    $steer = PendingSteeringMessage::create([
        'root_task_id' => YakTask::factory()->create()->id,
        'text' => 'also the footer',
        'source' => 'web',
        'mode' => 'steer',
        'uuid' => 'steer-1',
    ]);
    ThreadTurnMessage::factory()->steer()->create([
        'task_run_id' => $run->id,
        'uuid' => 'steer-1',
        'pending_steering_message_id' => $steer->id,
    ]);

    pushRunnerLine($thread, 1, lifecycleLine('steer-1', 'queued'));

    expect($steer->refresh()->delivered_at)->not->toBeNull()
        ->and($steer->absorbed_at)->toBeNull();

    pushRunnerLine($thread, 2, lifecycleLine('steer-1', 'started'));

    expect($steer->refresh()->absorbed_at)->not->toBeNull();
});

it('adds cost per result and counts assistant turns', function () {
    bindTrackerChanges([new TurnTrackerChange(costDeltaUsd: 0.5), new TurnTrackerChange(costDeltaUsd: 0.25)]);
    [$thread, $run] = runningTurnFor();

    pushRunnerLine($thread, 1, ['type' => 'assistant', 'message' => ['content' => []]]);
    pushRunnerLine($thread, 2, ['type' => 'assistant', 'message' => ['content' => []]]);

    expect((float) $run->refresh()->recorded_cost_usd)->toBe(0.75)
        ->and($run->assistant_turns)->toBe(2)
        ->and($run->turn_state)->toBe(TurnState::Running);
});

it('keeps tracker state as json on the run', function () {
    [$thread, $run] = runningTurnFor();

    pushRunnerLine($thread, 1, ['type' => 'assistant', 'message' => ['content' => []]]);

    expect(json_decode((string) $run->refresh()->getAttributes()['tracker_state'], true))->toBeArray()->not->toBeEmpty();
});

it('records the session once', function () {
    [$thread, $run] = runningTurnFor();
    $init = ['type' => 'system', 'subtype' => 'init', 'session_id' => 'session-abc', 'cwd' => '/workspace'];

    pushRunnerLine($thread, 1, $init);
    pushRunnerLine($thread, 2, $init);

    expect(ThreadSession::query()->where('thread_id', $thread->id)->count())->toBe(1)
        ->and(ThreadSession::query()->first()->session_id)->toBe('session-abc')
        ->and($run->refresh()->session_id)->toBe('session-abc');
});
