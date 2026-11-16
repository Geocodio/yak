<?php

use App\Contracts\AgentRunner;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Exceptions\ClaudeAuthException;
use App\Jobs\SendNotificationJob;
use App\Jobs\SetupYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\ClaudeAuthDetector;
use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAgentRunner;
use Tests\Support\FakeSandboxManager;

/*
|--------------------------------------------------------------------------
| ClaudeAuthDetector Unit Tests
|--------------------------------------------------------------------------
*/

test('detects auth error from non-zero exit with auth message', function () {
    Process::fake([
        'claude *' => Process::result(
            output: '',
            errorOutput: 'Error: Not authenticated. Please run `claude login` to authenticate.',
            exitCode: 1,
        ),
    ]);

    $result = Process::run('claude -p test');

    expect(ClaudeAuthDetector::isAuthError($result))->toBeTrue();
});

test('does not flag successful process as auth error', function () {
    Process::fake([
        'claude *' => Process::result(
            output: json_encode(['result' => 'ok']),
            exitCode: 0,
        ),
    ]);

    $result = Process::run('claude -p test');

    expect(ClaudeAuthDetector::isAuthError($result))->toBeFalse();
});

test('does not flag non-auth errors as auth error', function () {
    Process::fake([
        'claude *' => Process::result(
            output: '',
            errorOutput: 'Error: Rate limit exceeded',
            exitCode: 1,
        ),
    ]);

    $result = Process::run('claude -p test');

    expect(ClaudeAuthDetector::isAuthError($result))->toBeFalse();
});

test('detects various auth error patterns', function () {
    $patterns = [
        'Error: token expired',
        'authentication_error: invalid_api_key',
        'Error: subscription expired, please renew',
        'Unauthorized access',
        'Error: session expired, please login again',
    ];

    foreach ($patterns as $pattern) {
        Process::fake([
            'claude *' => Process::result(
                output: '',
                errorOutput: $pattern,
                exitCode: 1,
            ),
        ]);

        $result = Process::run('claude -p test');

        expect(ClaudeAuthDetector::isAuthError($result))->toBeTrue();
    }
});

test('does not flag a noisy failed agent run containing 401 in a UUID or duration_ms as an auth error', function () {
    $payload = json_encode([
        'session_id' => '4014abcd-0140-4a10-9401-abcdef123456',
        'duration_ms' => 214012,
        'total_cost_usd' => 0.0401,
        'is_error' => true,
        'result' => 'Ran the test suite against the repo\'s own auth middleware; the unauthenticated-request case passed as expected.',
    ]);

    Process::fake([
        'claude *' => Process::result(
            output: $payload,
            errorOutput: '',
            exitCode: 1,
        ),
    ]);

    $result = Process::run('claude -p test');

    expect(ClaudeAuthDetector::isAuthError($result))->toBeFalse();
});

test('does not flag the stale oauth refresh lock path as an auth error', function () {
    Process::fake([
        'claude *' => Process::result(
            output: '',
            errorOutput: 'Failed to acquire lock: /home/yak/.claude/.oauth_refresh.lock is held',
            exitCode: 1,
        ),
    ]);

    $result = Process::run('claude -p test');

    expect(ClaudeAuthDetector::isAuthError($result))->toBeFalse();
});

test('still flags anchored oauth and 401 phrasing as an auth error', function () {
    $patterns = [
        'Error: http 401 Unauthorized',
        'Error: status 401 from API',
        'Error: oauth token invalid',
        'Error: oauth error during refresh',
    ];

    foreach ($patterns as $pattern) {
        Process::fake([
            'claude *' => Process::result(
                output: '',
                errorOutput: $pattern,
                exitCode: 1,
            ),
        ]);

        $result = Process::run('claude -p test');

        expect(ClaudeAuthDetector::isAuthError($result))->toBeTrue();
    }
});

test('formats auth error message with details', function () {
    Process::fake([
        'claude *' => Process::result(
            output: '',
            errorOutput: 'Not authenticated. Please run `claude login`.',
            exitCode: 1,
        ),
    ]);

    $result = Process::run('claude -p test');

    $message = ClaudeAuthDetector::formatErrorMessage($result);

    expect($message)->toContain('Claude CLI authentication error')
        ->and($message)->toContain('Not authenticated')
        ->and($message)->toContain('re-authenticate');
});

/*
|--------------------------------------------------------------------------
| SetupYakJob Auth Error Detection
|--------------------------------------------------------------------------
*/

test('SetupYakJob detects auth error and fails task with notification', function () {
    Queue::fake([SendNotificationJob::class]);

    $fake = (new FakeAgentRunner)->queueException(
        new ClaudeAuthException('Claude CLI authentication error: Not authenticated. Please run `claude login`.')
    );
    $this->app->instance(AgentRunner::class, $fake);
    $this->app->instance(IncusSandboxManager::class, new FakeSandboxManager);

    Process::fake(['*' => Process::result('')]);

    $repository = Repository::factory()->create(['slug' => 'setup-repo', 'path' => '/home/yak/repos/setup-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'setup-repo', 'source' => 'slack']);

    $job = new SetupYakJob($task);
    $job->handle($fake);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toContain('Claude CLI authentication error');

    Queue::assertPushed(SendNotificationJob::class, function (SendNotificationJob $job) {
        return $job->type === NotificationType::Error;
    });
});

/*
|--------------------------------------------------------------------------
| Health Check Claude Auth Verification
|--------------------------------------------------------------------------
*/
