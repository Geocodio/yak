<?php

namespace App\Services\HealthCheck;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;

class ClaudeAuthCheck implements HealthCheck
{
    public function id(): string
    {
        return 'claude-auth';
    }

    public function name(): string
    {
        return 'Claude CLI Auth';
    }

    public function section(): HealthSection
    {
        return HealthSection::System;
    }

    public function run(): HealthResult
    {
        // Run as the yak user — credentials live at /home/yak/.claude and are
        // not readable by www-data, so invoking `claude auth status` directly
        // from php-fpm hangs instead of failing fast.
        $command = 'sudo runuser -u yak -- env HOME=/home/yak claude auth status';

        try {
            $result = Process::timeout(15)->run($command);
        } catch (ProcessTimedOutException|SymfonyProcessTimedOutException) {
            return HealthResult::error('Timed out');
        }

        if ($result->successful()) {
            return HealthResult::ok('Authenticated');
        }

        return HealthResult::error('Claude CLI not authenticated — run `claude login` to re-authenticate');
    }
}
