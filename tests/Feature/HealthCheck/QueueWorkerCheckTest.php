<?php

use App\Services\HealthCheck\HealthSection;
use App\Services\HealthCheck\HealthStatus;
use App\Services\HealthCheck\QueueWorkerCheck;
use Illuminate\Support\Facades\Process;

it('returns Ok with the PID when a worker is running', function () {
    Process::fake([
        'pgrep *' => Process::result(output: "4242\n"),
    ]);

    $result = (new QueueWorkerCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->detail)->toBe('Running, PID 4242');
});

it('returns Error when no worker is running', function () {
    Process::fake([
        'pgrep *' => Process::result(exitCode: 1),
    ]);

    $result = (new QueueWorkerCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error);
    expect($result->detail)->toBe('Not running');
});

it('has system identity metadata', function () {
    expect((new QueueWorkerCheck)->section())->toBe(HealthSection::System);
    expect((new QueueWorkerCheck)->id())->toBe('queue-worker');
    expect((new QueueWorkerCheck)->name())->toBe('Queue Worker');
});
