<?php

namespace App\Services\HealthCheck;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;

class QueueWorkerCheck implements HealthCheck
{
    public function id(): string
    {
        return 'queue-worker';
    }

    public function name(): string
    {
        return 'Queue Worker';
    }

    public function section(): HealthSection
    {
        return HealthSection::System;
    }

    public function run(): HealthResult
    {
        try {
            $result = Process::timeout(5)->run('pgrep -f "artisan queue:work"');
        } catch (ProcessTimedOutException|SymfonyProcessTimedOutException) {
            return HealthResult::error('Timed out checking worker');
        }

        if ($result->successful() && trim($result->output()) !== '') {
            $pid = (int) trim(explode("\n", trim($result->output()))[0]);

            return HealthResult::ok("Running, PID {$pid}");
        }

        return HealthResult::error('Not running');
    }
}
