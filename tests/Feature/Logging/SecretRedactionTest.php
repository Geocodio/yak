<?php

use App\Logging\RedactSecretsTap;
use App\Models\ClaudeAccount;
use App\Models\TaskLog;
use App\Models\YakTask;
use App\Services\TaskLogger;
use App\Services\Threads\AuthProxyPlaceholders;
use App\Services\Threads\CredentialSocketServer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;

it('redacts secrets from task log message and metadata', function () {
    $task = YakTask::factory()->create();

    $log = TaskLogger::error($task, 'failed with sk-ant-oat01-abcdefghijklmnop', ['token' => 'yakph-' . str_repeat('A', 43)]);

    $row = TaskLog::find($log->id);
    expect($row->message)->toContain('[redacted]')->not->toContain('sk-ant')
        ->and(json_encode($row->metadata))->toContain('[redacted]')->not->toContain('yakph-');
});

it('redacts secrets and exception text on the yak log channel', function () {
    $channel = Log::channel('yak');
    (new RedactSecretsTap)($channel);
    $handler = new TestHandler;
    $channel->getLogger()->pushHandler($handler);

    $channel->error('boom sk-ant-api03-abcdefghijkl', ['exception' => new RuntimeException('leaked yakph-AAAAAAAAAAAAAAAAAAAAAAAA')]);

    $record = $handler->getRecords()[0];
    $dump = $record->message . json_encode($record->context);
    expect($dump)->toContain('[redacted]')->not->toContain('sk-ant')->not->toContain('yakph-A');
});

it('a real account secret never reaches the task log, the log record or a socket refusal', function () {
    $account = ClaudeAccount::factory()->create();
    $secret = $account->secret;
    $task = YakTask::factory()->create();

    $channel = Log::channel('yak');
    (new RedactSecretsTap)($channel);
    $handler = new TestHandler;
    $channel->getLogger()->pushHandler($handler);

    $exception = new RuntimeException('auth failed for ' . $secret);
    $log = TaskLogger::error($task, $exception->getMessage(), ['error' => $exception->getMessage()]);
    $channel->error($exception->getMessage(), ['exception' => $exception]);

    $record = $handler->getRecords()[0];
    expect(json_encode(TaskLog::find($log->id)->getAttributes()))->not->toContain($secret)
        ->and($record->message . json_encode($record->context))->not->toContain($secret);

    $path = sys_get_temp_dir() . '/yk' . getmypid() . '-' . Str::random(6) . '.sock';
    $server = new CredentialSocketServer(app(AuthProxyPlaceholders::class), fn () => 1);
    $server->listen($path, 2);
    $placeholder = app(AuthProxyPlaceholders::class)->issue('run:1', $account, '172.20.0.5');

    $replies = [];
    foreach ([[$placeholder, '172.20.0.5'], ['yakph-' . str_repeat('A', 43), '172.20.0.5']] as $index => [$candidate, $ip]) {
        $server = $index === 0 ? $server : new CredentialSocketServer(app(AuthProxyPlaceholders::class), fn () => 2);
        if ($index === 1) {
            $server->listen($path, 2);
        }
        $client = stream_socket_client('unix://' . $path, $code, $message, 2);
        fwrite($client, json_encode(['placeholder' => $candidate, 'container_ip' => $ip]) . "\n");
        $server->serve(500_000);
        $replies[] = (string) fgets($client);
        fclose($client);
        $server->close();
    }
    @unlink($path);

    expect($replies[0])->toContain('forbidden')->not->toContain($secret)
        ->and($replies[1])->toContain('unknown')->not->toContain($secret);
});
