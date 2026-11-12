<?php

use App\Enums\RunnerState;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Services\ClaudeAccounts\ClaudeModelCatalog;
use App\Services\Threads\AuthProxyPlaceholders;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\ThreadCommands;
use Illuminate\Support\Facades\Process;

function commandsThread(): Thread
{
    return Thread::factory()->create(['sandbox_container' => 'thread-1', 'sandbox_state' => 'running', 'model' => 'opus']);
}

it('sends start_runner with the placeholder and never a credential', function () {
    $thread = commandsThread();
    $account = ClaudeAccount::factory()->create(['secret' => 'sk-ant-oat01-supersecret']);
    app(SandboxManager::class)->queueExecResult(Process::result("10.100.0.7 fd42::1 \n"));

    $placeholders = Mockery::mock(AuthProxyPlaceholders::class);
    $placeholders->shouldReceive('issue')->once()->with(Mockery::on(fn ($subject) => $subject->is($thread)), Mockery::on(fn ($given) => $given->is($account)), '10.100.0.7')->andReturn('yakph-test');

    (new ThreadCommands(app(BrokerStreams::class), app(SandboxManager::class), $placeholders, app(ClaudeModelCatalog::class)))
        ->startRunner($thread, $account, 'session-1', 'Be brief.');

    $command = app(BrokerStreams::class)->commands('threads:' . $thread->id)[0];

    expect($command['type'])->toBe('start_runner')
        ->and($command['container'])->toBe('thread-1')
        ->and($command['uid'])->toBe(1000)
        ->and($command['cwd'])->toBe('/workspace')
        ->and($command['start']['type'])->toBe('start')
        ->and($command['start']['model'])->toBe('claude-opus-5-5')
        ->and($command['start']['appendSystemPrompt'])->toBe('Be brief.')
        ->and($command['start']['permissionMode'])->toBe('bypassPermissions')
        ->and($command['start']['resume'])->toBe('session-1')
        ->and($command['start']['settingSources'])->toBe(['user', 'project', 'local'])
        ->and($command['start']['claudeConfigDir'])->toBe($thread->claude_config_dir)
        ->and($command['start']['placeholder'])->toBe('yakph-test')
        ->and($command['start']['anthropicBaseUrl'])->toBe(config('yak.threads.auth_proxy_url'))
        ->and($command['start']['disallowedTools'])->toBe(['ScheduleWakeup', 'CronCreate', 'CronDelete', 'CronList'])
        ->and(json_encode($command))->not->toContain('supersecret');

    $thread->refresh();
    expect($thread->runner_state)->toBe(RunnerState::Starting)
        ->and($thread->getAttribute('runner_model'))->toBe('opus')
        ->and($thread->getAttribute('append_system_prompt'))->toBe('Be brief.');
});

it('points the runner at the pushed MCP config only when the host has one', function () {
    $thread = commandsThread();
    $placeholders = Mockery::mock(AuthProxyPlaceholders::class);
    $placeholders->shouldReceive('issue')->andReturn('yakph');
    $commands = new ThreadCommands(app(BrokerStreams::class), app(SandboxManager::class), $placeholders, app(ClaudeModelCatalog::class));
    app(SandboxManager::class)->queueExecResult(Process::result("10.100.0.7 \n"));
    app(SandboxManager::class)->queueExecResult(Process::result("10.100.0.7 \n"));
    $account = ClaudeAccount::factory()->create();

    config(['yak.mcp_config_path' => null]);
    $commands->startRunner($thread, $account, null, 'x');

    $file = tempnam(sys_get_temp_dir(), 'mcp');
    config(['yak.mcp_config_path' => $file]);
    $commands->startRunner($thread, $account, null, 'x');
    unlink($file);

    $sent = app(BrokerStreams::class)->commands('threads:' . $thread->id);
    expect($sent[0]['start']['mcpConfigPath'])->toBeNull()
        ->and($sent[1]['start']['mcpConfigPath'])->toBe('/home/yak/mcp-config.json');
});

it('sends each command type', function (Closure $send, array $expected) {
    $thread = commandsThread();
    $commands = new ThreadCommands(app(BrokerStreams::class), app(SandboxManager::class), Mockery::mock(AuthProxyPlaceholders::class), app(ClaudeModelCatalog::class));

    $send($commands, $thread);

    expect(app(BrokerStreams::class)->commands('threads:' . $thread->id))->toBe([$expected])
        ->and(app(BrokerStreams::class)->channels('threads:'))->toBe(['threads:' . $thread->id]);
})->with([
    'turn' => [fn ($commands, $thread) => $commands->turn($thread, 'u1', 'hello'), ['type' => 'turn', 'uuid' => 'u1', 'text' => 'hello']],
    'turn with priority' => [fn ($commands, $thread) => $commands->turn($thread, 'u1', 'hello', 'now'), ['type' => 'turn', 'uuid' => 'u1', 'text' => 'hello', 'priority' => 'now']],
    'steer' => [fn ($commands, $thread) => $commands->steer($thread, 'u2', 'psst', 'next'), ['type' => 'steer', 'uuid' => 'u2', 'text' => 'psst', 'priority' => 'next']],
    'interrupt' => [fn ($commands, $thread) => $commands->interrupt($thread), ['type' => 'interrupt']],
    'set_model' => [fn ($commands, $thread) => $commands->setModel($thread, 'sonnet'), ['type' => 'set_model', 'model' => 'claude-sonnet-5-5']],
    'end' => [fn ($commands, $thread) => $commands->end($thread), ['type' => 'end']],
    'kill' => [fn ($commands, $thread) => $commands->kill($thread), ['type' => 'kill']],
    'tool_result' => [fn ($commands, $thread) => $commands->toolResult($thread, 'c1', true, 'nope'), ['type' => 'tool_result', 'call_id' => 'c1', 'is_error' => true, 'content' => 'nope']],
]);

it('tracks the runner state for model, end and the channel name', function () {
    $thread = commandsThread();
    $commands = new ThreadCommands(app(BrokerStreams::class), app(SandboxManager::class), Mockery::mock(AuthProxyPlaceholders::class), app(ClaudeModelCatalog::class));

    $commands->setModel($thread, 'sonnet');
    $commands->end($thread);

    expect($commands->channel($thread))->toBe('threads:' . $thread->id)
        ->and($thread->fresh()->runner_state)->toBe(RunnerState::Stopping)
        ->and(Thread::query()->whereKey($thread->id)->value('runner_model'))->toBe('sonnet');
});
