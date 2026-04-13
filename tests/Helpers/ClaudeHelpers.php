<?php

use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Fake a successful Claude CLI run with configurable result.
 *
 * @param  array<string, mixed>  $result  Override fields in the Claude JSON output
 * @param  array<string, FakeProcessResult>  $extraFakes  Additional Process::fake patterns
 */
function fakeClaudeRun(array $result = [], array $extraFakes = []): void
{
    $defaults = [
        'result' => 'Task completed successfully',
        'cost_usd' => 1.50,
        'session_id' => 'sess_fake_' . uniqid(),
        'num_turns' => 10,
        'duration_ms' => 60000,
        'is_error' => false,
    ];

    $output = json_encode(array_merge($defaults, $result));

    Process::fake(array_merge([
        'docker compose stop' => Process::result(''),
        'lsof *' => Process::result(''),
        '*git fetch *' => Process::result(''),
        '*git checkout -b *' => Process::result(''),
        '*git checkout *' => Process::result(''),
        '*git push *' => Process::result(''),
        'sudo *' => Process::result($output),
    ], $extraFakes));
}

/**
 * Fake a Claude CLI clarification response.
 *
 * @param  array<int, string>  $options  Clarification options to return
 * @param  array<string, mixed>  $result  Override fields in the Claude JSON output
 */
function fakeClaudeClarification(array $options = ['Option A', 'Option B', 'Option C'], array $result = []): void
{
    $defaults = [
        'clarification_needed' => true,
        'options' => $options,
        'session_id' => 'sess_clarify_' . uniqid(),
        'cost_usd' => 0.75,
        'num_turns' => 5,
        'duration_ms' => 30000,
    ];

    $output = json_encode(array_merge($defaults, $result));

    Process::fake([
        'docker compose stop' => Process::result(''),
        'lsof *' => Process::result(''),
        '*git fetch *' => Process::result(''),
        '*git checkout -b *' => Process::result(''),
        '*git checkout *' => Process::result(''),
        '*git push *' => Process::result(''),
        'sudo *' => Process::result($output),
    ]);
}

/**
 * Fake a Claude CLI error response.
 *
 * @param  string  $message  The error message
 * @param  array<string, mixed>  $result  Override fields in the Claude JSON output
 */
function fakeClaudeError(string $message = 'Claude encountered an error', array $result = []): void
{
    $defaults = [
        'is_error' => true,
        'result' => $message,
        'session_id' => 'sess_error_' . uniqid(),
        'cost_usd' => 0.25,
        'num_turns' => 1,
        'duration_ms' => 5000,
    ];

    $output = json_encode(array_merge($defaults, $result));

    Process::fake([
        'docker compose stop' => Process::result(''),
        'lsof *' => Process::result(''),
        '*git fetch *' => Process::result(''),
        '*git checkout -b *' => Process::result(''),
        '*git checkout *' => Process::result(''),
        '*git push *' => Process::result(''),
        'sudo *' => Process::result($output),
    ]);
}
