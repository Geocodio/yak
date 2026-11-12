<?php

use App\DataTransferObjects\YakToolCall;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnMessageState;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadTurns;
use App\Services\Threads\YakToolRouter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;

it('runs a natural turn from start to pause and keeps a private thread off another user\'s list', function () {
    Event::fake([TurnCompleted::class]);
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $account = ClaudeAccount::factory()->create();
    $thread = Thread::factory()->private($owner)->create(['claude_account_id' => $account->id, 'model' => 'sonnet']);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);

    $run = app(ThreadTurns::class)->start($task, 'scrubbed text 1', $owner, null);
    expect($run->turn_state)->toBe(TurnState::Running);

    $streams = app(BrokerStreams::class);
    $driver = app(ThreadDriver::class);
    $channel = 'threads:' . $thread->id;
    $sequence = 0;

    foreach (file(base_path('tests/Fixtures/runner-streams/natural-turn-end.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $raw) {
        $line = json_decode(str_replace('f429399e-374d-5e1e-a7cc-65fce3bd2a28', $run->prompt_uuid, $raw), true);

        if (isset($line['fixture'])) {
            continue;
        }

        $driver->handle($streams->pushOut($channel, 1, ++$sequence, $line, fromBroker: str_starts_with((string) ($line['type'] ?? ''), 'broker')));
    }

    $this->travel(60)->seconds();
    $driver->tick();
    $driver->tick();

    $run->refresh();
    expect($run->turn_state)->toBe(TurnState::Completed)
        ->and((float) $run->recorded_cost_usd)->toBeGreaterThan(0)
        ->and(ThreadTurnMessage::query()->where('task_run_id', $run->id)->pluck('state')->unique()->all())->toBe([TurnMessageState::Completed])
        ->and(collect(app(SandboxManager::class)->calls)->pluck('operation')->all())->toContain('pause')
        ->and($thread->refresh()->sandbox_state)->toBe(ThreadSandboxState::Paused)
        ->and(app(SessionFileSync::class)->synced)->toContain($thread->id);
    Event::assertDispatchedTimes(TurnCompleted::class, 1);

    $this->actingAs($other)->getJson(route('threads.list', ['filter' => 'everyone']))
        ->assertOk()->assertJsonCount(0, 'threads')->assertJsonCount(0, 'needsYou');
    $this->actingAs($owner)->getJson(route('threads.list'))->assertJsonCount(1, 'needsYou');
});

it('serves request_refresh through the resolved tool router', function () {
    $thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running, 'repo' => 'acme/app']);
    $sandbox = app(SandboxManager::class);
    $sandbox->queueExecResult(Process::result("refresh:\n  composer.lock: composer install\n"));
    $sandbox->queueExecResult(Process::result("README.md\n"));

    $result = app(YakToolRouter::class)->handle($thread, new YakToolCall('c1', 'request_refresh', []));

    expect($result->content)->not->toContain('not available yet')->toContain('Nothing to refresh');
});
