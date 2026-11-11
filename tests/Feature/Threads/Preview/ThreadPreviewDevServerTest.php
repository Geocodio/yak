<?php

use App\Jobs\Threads\StartThreadPreviewJob;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Preview\ThreadPreviewDevServer;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

require_once __DIR__ . '/../../../Support/ThreadPreviewHelpers.php';

function sandboxCommands(): array
{
    return array_map(fn (array $call): array => $call['arguments']['command'], app(SandboxManager::class)->calls);
}

it('skips cold_start when the probe is healthy', function () {
    Queue::fake();
    $thread = previewSandboxThread();
    queueSandboxOutput("port: 3000\nhealth_probe_path: /up\ncold_start: bin/serve\n");
    queueSandboxOutput('');

    $state = app(ThreadPreviewDevServer::class)->ensureStarted($thread);

    expect($state)->toBe('ready')->and(app(ThreadPreviewDevServer::class)->state($thread))->toBe('ready');
    Queue::assertNothingPushed();
});

it('starts cold_start once even when two requests arrive together', function () {
    Queue::fake();
    $thread = previewSandboxThread();

    foreach ([1, 2] as $request) {
        queueSandboxOutput("port: 3000\nhealth_probe_path: /up\ncold_start: bin/serve\n");
        queueSandboxOutput('curl: (7) refused', 7);
    }

    $first = app(ThreadPreviewDevServer::class)->ensureStarted($thread);
    $second = app(ThreadPreviewDevServer::class)->ensureStarted($thread);

    expect([$first, $second])->toBe(['starting', 'starting']);
    Queue::assertPushed(StartThreadPreviewJob::class, 1);
});

it('runs cold_start in the background and becomes ready once the probe answers', function () {
    Sleep::fake();
    $thread = previewSandboxThread();
    queueSandboxOutput("port: 3000\nhealth_probe_path: /up\ncold_start: bin/serve\n");
    queueSandboxOutput('');
    queueSandboxOutput('', 7);
    queueSandboxOutput('');
    queueSandboxOutput("listening\n");

    app(ThreadPreviewDevServer::class)->runColdStart($thread);

    $commands = sandboxCommands();
    expect($commands[1][0])->toBe('bash')->and($commands[1][4])->toBe('bin/serve')
        ->and(app(ThreadPreviewDevServer::class)->state($thread))->toBe('ready')
        ->and(app(ThreadPreviewDevServer::class)->logTail($thread))->toBe(['listening']);
});

it('becomes failing when the probe keeps failing after the cold start timeout', function () {
    Sleep::fake(syncWithCarbon: true);
    $this->travelTo(now()->startOfSecond());
    $thread = previewSandboxThread();
    queueSandboxOutput("port: 3000\nhealth_probe_path: /up\ncold_start: bin/serve\ncold_start_timeout_seconds: 3\n");
    queueSandboxOutput('');
    queueSandboxResults(10, 'connection refused', 7);

    app(ThreadPreviewDevServer::class)->runColdStart($thread);

    expect(app(ThreadPreviewDevServer::class)->state($thread))->toBe('failing')
        ->and(app(ThreadPreviewDevServer::class)->error($thread))->toContain('connection refused');
});

it('redacts the log tail and caps it at 40 lines', function () {
    Sleep::fake();
    $thread = previewSandboxThread();
    queueSandboxOutput("port: 3000\nhealth_probe_path: /up\ncold_start: bin/serve\n");
    queueSandboxOutput('');
    queueSandboxOutput('');
    $lines = array_map(fn (int $number): string => "line {$number}", range(1, 60));
    $lines[59] = 'token sk-ant-abcdefgh12345678';
    queueSandboxOutput(implode("\n", $lines));

    app(ThreadPreviewDevServer::class)->runColdStart($thread);

    $tail = app(ThreadPreviewDevServer::class)->logTail($thread);
    expect($tail)->toHaveCount(40)->and($tail[0])->toBe('line 21')->and($tail[39])->toBe('token [redacted]');
});

it('reports no_manifest and runs nothing when the file is missing', function () {
    Queue::fake();
    $thread = previewSandboxThread();
    queueSandboxOutput('', 1);

    expect(app(ThreadPreviewDevServer::class)->ensureStarted($thread))->toBe('no_manifest')
        ->and(sandboxCommands())->toHaveCount(1);
    Queue::assertNothingPushed();
});
