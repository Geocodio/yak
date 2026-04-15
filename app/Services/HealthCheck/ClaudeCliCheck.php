<?php

namespace App\Services\HealthCheck;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;

/**
 * Verifies the `claude` CLI binary is installed in the yak app container.
 *
 * The CLI is needed here for two things only — neither of which is task
 * execution itself (that happens inside Incus sandboxes):
 *  1. Initial `claude login` to populate /home/yak/.claude.
 *  2. The /skills dashboard, which manages plugins via SkillManager.
 */
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
            $result = Process::timeout(10)->run('claude --version');
        } catch (ProcessTimedOutException|SymfonyProcessTimedOutException) {
            return HealthResult::error('Timed out');
        }

        if ($result->successful()) {
            return HealthResult::ok('Available, ' . trim($result->output()));
        }

        return HealthResult::error('Not installed in the yak container — run `npm install -g @anthropic-ai/claude-code`');
    }
}
