<?php

use App\Enums\RunnerState;
use App\Enums\ThreadSessionKind;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Services\Threads\ThreadCommands;
use App\Services\Threads\ThreadStreams;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeThreadStreams;

beforeEach(function () {
    $this->streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $this->streams);
    $this->thread = Thread::factory()->paused()->create();
    $this->account = ClaudeAccount::factory()->create(['secret' => 'sk-ant-secret-value']);
});

it('registers the channel before the first command', function () {
    app(ThreadCommands::class)->turn($this->thread, 'u1', 'hello');

    expect($this->streams->events)->toBe(['register:threads:' . $this->thread->id, 'command:turn']);
});

it('puts the credential only inside start_runner', function () {
    $commands = app(ThreadCommands::class);
    $commands->startRunner($this->thread, $this->account, null, 'prompt');
    $commands->turn($this->thread, 'u1', 'hello');
    $commands->steer($this->thread, 'u2', 'more', 'now');
    $commands->interrupt($this->thread);
    $commands->setModel($this->thread, 'sonnet');
    $commands->end($this->thread);
    $commands->kill($this->thread);

    $sent = $this->streams->commandsFor($this->thread->channel());

    expect(array_column($sent, 'type'))->toBe(['start_runner', 'turn', 'steer', 'interrupt', 'set_model', 'end', 'kill']);
    expect($sent[0]['start']['credential']['value'])->toBe('sk-ant-secret-value');
    expect($sent[0]['container'])->toBe($this->thread->sandbox_container);
    expect($sent[2]['priority'])->toBe('now');

    foreach (array_slice($sent, 1) as $command) {
        expect(json_encode($command))->not->toContain('sk-ant-secret-value');
    }

    expect($this->thread->fresh()->runner_state)->toBe(RunnerState::Starting);
});

it('never logs the credential', function () {
    Log::spy();

    app(ThreadCommands::class)->startRunner($this->thread, $this->account, null, 'prompt');

    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('debug');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

it('sends resume when the thread has an active session', function () {
    $this->thread->sessions()->create(['session_id' => 's-main', 'kind' => ThreadSessionKind::Main]);
    $session = $this->thread->activeSession();

    $commands = app(ThreadCommands::class);
    $commands->startRunner($this->thread, $this->account, $session->session_id, 'prompt');
    $commands->startRunner($this->thread, $this->account, null, 'prompt');

    $sent = $this->streams->commandsFor($this->thread->channel());

    expect($sent[0]['start']['resume'])->toBe('s-main')
        ->and($sent[1]['start']['resume'])->toBeNull();
});

it('reads mcp servers from the configured file', function () {
    $path = tempnam(sys_get_temp_dir(), 'mcp');
    file_put_contents($path, json_encode(['mcpServers' => ['linear' => ['type' => 'http', 'url' => 'https://example.test']]]));
    config(['yak.mcp_config_path' => $path]);

    app(ThreadCommands::class)->startRunner($this->thread, $this->account, null, 'prompt');

    $servers = $this->streams->commandsFor($this->thread->channel())[0]['start']['mcpServers'];

    expect(json_decode(json_encode($servers), true))->toBe(['linear' => ['type' => 'http', 'url' => 'https://example.test']]);
    unlink($path);
});

it('wraps nothing around turn text', function () {
    app(ThreadCommands::class)->turn($this->thread, 'u1', "  raw\ntext ");

    expect($this->streams->commandsFor($this->thread->channel())[0])->toBe(['type' => 'turn', 'uuid' => 'u1', 'text' => "  raw\ntext "]);
});
