<?php

namespace App\Services\HealthCheck;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;

class ClaudeCliCheck implements HealthCheck
{
    public function id(): string
    {
        return 'claude-cli';
    }

    public function name(): string
    {
        return 'Claude CLI';
    }

    public function section(): HealthSection
    {
        return HealthSection::System;
    }

    public function run(): HealthResult
    {
        try {
            $result = Process::timeout(15)->run('claude --version');
        } catch (ProcessTimedOutException|SymfonyProcessTimedOutException) {
            return HealthResult::error('Timed out');
        }

        if ($result->successful()) {
            $version = trim($result->output());

            return HealthResult::ok("Responding, {$version}");
        }

        return HealthResult::error('Not responding');
    }
}
