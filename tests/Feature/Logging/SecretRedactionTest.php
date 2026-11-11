<?php

use App\Logging\RedactSecretsTap;
use App\Models\TaskLog;
use App\Models\YakTask;
use App\Services\TaskLogger;
use Illuminate\Support\Facades\Log;
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
