<?php

use App\DataTransferObjects\ParsedReview;
use App\DataTransferObjects\ReviewFinding;
use App\Services\ReviewOutputParser;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Bind a ReviewOutputParser mock that returns a canned ParsedReview, so
 * job tests don't call the real Laravel AI structurer. Pass a list of
 * raw finding arrays; sensible defaults fill the rest.
 *
 * @param  array<int, array<string, mixed>>  $findings
 */
function fakeReviewParser(
    array $findings = [],
    string $summary = 'Fake review for tests.',
    string $verdict = 'Approve',
    string $verdictDetail = 'ok',
): void {
    $dtoFindings = array_map(
        fn (array $raw): ReviewFinding => ReviewFinding::fromArray($raw),
        $findings,
    );

    $parser = Mockery::mock(ReviewOutputParser::class);
    $parser->shouldReceive('parse')->andReturn(new ParsedReview(
        summary: $summary,
        verdict: $verdict,
        verdictDetail: $verdictDetail,
        findings: $dtoFindings,
    ));

    app()->instance(ReviewOutputParser::class, $parser);
}

/**
 * Build the standard set of git/docker fakes used across job tests.
 *
 * Uses a stateful closure so `git rev-parse --abbrev-ref HEAD` returns
 * the branch name from the most recent `git checkout` call — lets the
 * pre-push assertOnBranch check succeed without per-test branch config.
 *
 * @param  string  $claudeOutput  JSON output for sudo (Claude) calls
 * @param  array<string, FakeProcessResult>  $extraFakes  Additional Process::fake patterns
 */
function fakeYakProcess(string $claudeOutput, array $extraFakes = []): void
{
    $currentBranch = 'main';

    Process::fake(array_merge([
        'docker compose stop' => Process::result(''),
        'lsof *' => Process::result(''),
        '*git reset --hard' => Process::result(''),
        '*git clean -fd' => Process::result(''),
        '*git fetch *' => Process::result(''),
        '*git branch -D *' => Process::result(''),
        '*git push *' => Process::result(''),
        '*git pull *' => Process::result(''),
        '*git checkout -b *' => function ($process) use (&$currentBranch) {
            if (preg_match("/checkout -b '?([^' ]+)/", (string) $process->command, $m)) {
                $currentBranch = $m[1];
            }

            return Process::result('');
        },
        '*git checkout *' => function ($process) use (&$currentBranch) {
            if (preg_match("/git checkout '?([^' ]+)/", (string) $process->command, $m)) {
                $currentBranch = $m[1];
            }

            return Process::result('');
        },
        '*git rev-parse *' => fn () => Process::result(output: $currentBranch),
        'sudo *' => Process::result($claudeOutput),
    ], $extraFakes));
}

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

    fakeYakProcess((string) json_encode(array_merge($defaults, $result)), $extraFakes);
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

    fakeYakProcess((string) json_encode(array_merge($defaults, $result)));
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

    fakeYakProcess((string) json_encode(array_merge($defaults, $result)));
}
