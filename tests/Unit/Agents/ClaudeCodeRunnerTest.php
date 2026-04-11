<?php

use App\Agents\ClaudeCodeRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\Exceptions\ClaudeAuthException;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

function makeRequest(array $overrides = []): AgentRunRequest
{
    return new AgentRunRequest(
        prompt: $overrides['prompt'] ?? 'Fix the bug',
        systemPrompt: $overrides['systemPrompt'] ?? 'You are Yak',
        workingDirectory: $overrides['workingDirectory'] ?? '/home/yak/repos/test',
        timeoutSeconds: $overrides['timeoutSeconds'] ?? 570,
        maxBudgetUsd: $overrides['maxBudgetUsd'] ?? 5.0,
        maxTurns: $overrides['maxTurns'] ?? 40,
        model: $overrides['model'] ?? 'opus',
        resumeSessionId: $overrides['resumeSessionId'] ?? null,
        mcpConfigPath: $overrides['mcpConfigPath'] ?? null,
    );
}

it('invokes claude -p for a fresh run and returns an AgentRunResult', function () {
    Process::fake([
        'claude *' => Process::result(json_encode([
            'result' => 'done',
            'session_id' => 'sess_fresh',
            'cost_usd' => 1.25,
            'num_turns' => 10,
            'duration_ms' => 60000,
            'is_error' => false,
        ])),
    ]);

    $result = (new ClaudeCodeRunner)->run(makeRequest());

    expect($result->sessionId)->toBe('sess_fresh')
        ->and($result->costUsd)->toBe(1.25)
        ->and($result->isError)->toBeFalse();

    Process::assertRan(fn ($p) => str_contains($p->command, 'claude -p')
        && str_contains($p->command, '--dangerously-skip-permissions')
        && str_contains($p->command, '--bare')
        && str_contains($p->command, '--output-format json')
        && str_contains($p->command, '--model')
        && ! str_contains($p->command, '--resume')
    );
});

it('passes --resume when a session id is supplied', function () {
    Process::fake([
        'claude *' => Process::result(json_encode([
            'result' => 'retried',
            'session_id' => 'sess_resumed',
            'is_error' => false,
        ])),
    ]);

    (new ClaudeCodeRunner)->run(makeRequest(['resumeSessionId' => 'sess_resumed']));

    Process::assertRan(fn ($p) => str_contains($p->command, '--resume')
        && str_contains($p->command, 'sess_resumed')
    );
});

it('includes --mcp-config when an mcp config path is supplied', function () {
    Process::fake([
        'claude *' => Process::result(json_encode([
            'result' => 'ok',
            'session_id' => 's',
            'is_error' => false,
        ])),
    ]);

    (new ClaudeCodeRunner)->run(makeRequest(['mcpConfigPath' => '/etc/yak/mcp.json']));

    Process::assertRan(fn ($p) => str_contains($p->command, '--mcp-config')
        && str_contains($p->command, '/etc/yak/mcp.json')
    );
});

it('throws ClaudeAuthException when Claude returns an auth error', function () {
    Process::fake([
        'claude *' => Process::result(
            output: '',
            errorOutput: 'Error: not authenticated. Please run `claude login`',
            exitCode: 1,
        ),
    ]);

    (new ClaudeCodeRunner)->run(makeRequest());
})->throws(ClaudeAuthException::class);

it('returns a failure result for malformed output without throwing', function () {
    Process::fake([
        'claude *' => Process::result('not json at all'),
    ]);

    $result = (new ClaudeCodeRunner)->run(makeRequest());

    expect($result->isError)->toBeTrue()
        ->and($result->rawOutput)->toBe('not json at all');
});

it('runs the process from the working directory with the request timeout', function () {
    Process::fake([
        'claude *' => Process::result(json_encode([
            'result' => 'ok',
            'session_id' => 's',
            'is_error' => false,
        ])),
    ]);

    (new ClaudeCodeRunner)->run(makeRequest([
        'workingDirectory' => '/tmp/custom-repo',
        'timeoutSeconds' => 300,
    ]));

    Process::assertRan(function ($p) {
        return $p->path === '/tmp/custom-repo' && $p->timeout === 300;
    });
});
