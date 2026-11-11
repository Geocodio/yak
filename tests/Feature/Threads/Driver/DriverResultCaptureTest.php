<?php

use App\Models\ThreadTurnMessage;
use App\Services\Threads\ThreadTurns;

require_once __DIR__ . '/../../../Support/ThreadTurnHelpers.php';
require_once __DIR__ . '/../../../Support/ReplayFixture.php';

it('stores the last result line of the turn on the run', function () {
    $uuid = 'f429399e-374d-5e1e-a7cc-65fce3bd2a28';
    [$thread, $run] = runningTurnFor(['prompt_uuid' => $uuid, 'prompt' => 'scrubbed text 1']);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'uuid' => $uuid]);
    ThreadTurns::trackUuid($run, $uuid);

    replayFixtureThroughConsumer($thread, 'natural-turn-end');

    $event = $run->refresh()->last_result_event;

    expect($event)->toBeArray()->and($event['type'])->toBe('result');
});
