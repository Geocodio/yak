<?php

use App\Contracts\AgentRunner;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Exceptions\ClaudeAuthException;
use App\Jobs\ClarificationReplyJob;
use App\Jobs\ResearchYakJob;
use App\Jobs\RetryYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\SendNotificationJob;
use App\Jobs\SetupYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\ClaudeAuthDetector;
use App\Services\HealthCheck\ClaudeAuthCheck;
use App\Services\HealthCheck\ClaudeCliCheck;
use App\Services\HealthCheck\HealthStatus;
use App\Services\HealthCheck\Registry;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Tests\Support\FakeAgentRunner;

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
| RunYakJob Auth Error Detection
|--------------------------------------------------------------------------
*/

test('RunYakJob detects auth error and fails task with notification', function () {
    Queue::fake([SendNotificationJob::class]);

    $fake = (new FakeAgentRunner)->queueException(
        new ClaudeAuthException('Claude CLI authentication error: Not authenticated. Please run `claude login`.')
    );
    $this->app->instance(AgentRunner::class, $fake);

    Process::fake([
        'docker compose stop' => Process::result(''),
        'lsof *' => Process::result(''),
        '*git reset --hard' => Process::result(''),
        '*git clean -fd' => Process::result(''),
        '*git fetch *' => Process::result(''),
        '*git checkout -b *' => Process::result(''),
        '*git checkout *' => Process::result(''),
        '*git rev-parse *' => Process::result(output: 'yak/test'),
        '*git branch -D *' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'auth-repo', 'path' => '/home/yak/repos/auth-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'auth-repo', 'source' => 'slack']);

    $job = new RunYakJob($task);
    $job->handle($fake);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toContain('Claude CLI authentication error')
        ->and($task->completed_at)->not->toBeNull();

    Queue::assertPushed(SendNotificationJob::class, function (SendNotificationJob $job) use ($task) {
        return $job->task->id === $task->id
            && $job->type === NotificationType::Error
            && str_contains($job->message, 'authentication');
    });
});

/*
|--------------------------------------------------------------------------
| RetryYakJob Auth Error Detection
|--------------------------------------------------------------------------
*/

test('RetryYakJob detects auth error and fails task with notification', function () {
    Queue::fake([SendNotificationJob::class]);

    $fake = (new FakeAgentRunner)->queueException(
        new ClaudeAuthException('Claude CLI authentication error: token expired')
    );
    $this->app->instance(AgentRunner::class, $fake);

    Process::fake([
        'docker compose stop' => Process::result(''),
        'lsof *' => Process::result(''),
        '*git checkout *' => Process::result(''),
        '*git rev-parse *' => Process::result(output: 'yak/test'),
        '*git branch -D *' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'retry-repo', 'path' => '/home/yak/repos/retry-repo']);
    $task = YakTask::factory()->create([
        'repo' => 'retry-repo',
        'status' => TaskStatus::Retrying,
        'session_id' => 'sess_old',
        'source' => 'linear',
    ]);

    $job = new RetryYakJob($task);
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
| ResearchYakJob Auth Error Detection
|--------------------------------------------------------------------------
*/

test('ResearchYakJob detects auth error and fails task with notification', function () {
    Queue::fake([SendNotificationJob::class]);

    $fake = (new FakeAgentRunner)->queueException(
        new ClaudeAuthException('Claude CLI authentication error: authentication_error: invalid_api_key')
    );
    $this->app->instance(AgentRunner::class, $fake);

    Process::fake([
        '*git checkout *' => Process::result(''),
        '*git rev-parse *' => Process::result(output: 'yak/test'),
        '*git branch -D *' => Process::result(''),
        '*git pull *' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'research-repo', 'path' => '/home/yak/repos/research-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'research-repo', 'source' => 'slack']);

    $job = new ResearchYakJob($task);
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
| SetupYakJob Auth Error Detection
|--------------------------------------------------------------------------
*/

test('SetupYakJob detects auth error and fails task with notification', function () {
    Queue::fake([SendNotificationJob::class]);

    $fake = (new FakeAgentRunner)->queueException(
        new ClaudeAuthException('Claude CLI authentication error: Not authenticated. Please run `claude login`.')
    );
    $this->app->instance(AgentRunner::class, $fake);

    Process::fake([
        '*git clone *' => Process::result(''),
        'docker compose stop' => Process::result(''),
        'lsof *' => Process::result(''),
        '*git checkout *' => Process::result(''),
        '*git rev-parse *' => Process::result(output: 'yak/test'),
        '*git branch -D *' => Process::result(''),
        '*git pull *' => Process::result(''),
    ]);

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
| ClarificationReplyJob Auth Error Detection
|--------------------------------------------------------------------------
*/

test('ClarificationReplyJob detects auth error and fails task with notification', function () {
    Queue::fake([SendNotificationJob::class]);

    $fake = (new FakeAgentRunner)->queueException(
        new ClaudeAuthException('Claude CLI authentication error: session expired, please login again')
    );
    $this->app->instance(AgentRunner::class, $fake);

    Process::fake([
        'docker compose stop' => Process::result(''),
        'lsof *' => Process::result(''),
        '*git checkout *' => Process::result(''),
        '*git rev-parse *' => Process::result(output: 'yak/test'),
        '*git branch -D *' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'clarify-repo', 'path' => '/home/yak/repos/clarify-repo']);
    $task = YakTask::factory()->create([
        'repo' => 'clarify-repo',
        'status' => TaskStatus::AwaitingClarification,
        'session_id' => 'sess_clarify',
        'source' => 'slack',
    ]);

    $job = new ClarificationReplyJob($task, 'Use option A');
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

test('health check reports healthy when claude auth is valid', function () {
    Process::fake([
        'claude auth status' => Process::result('Authenticated as user@example.com'),
    ]);

    $result = (new ClaudeAuthCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->detail)->toBe('Authenticated');
});

test('health check reports unhealthy when claude auth fails', function () {
    Process::fake([
        'claude auth status' => Process::result(
            output: '',
            errorOutput: 'Not authenticated',
            exitCode: 1,
        ),
    ]);

    $result = (new ClaudeAuthCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->detail)->toContain('not authenticated');
});

test('health check reports unhealthy when claude auth times out', function () {
    Process::shouldReceive('timeout')->with(15)->andReturnSelf();
    Process::shouldReceive('run')->with('claude auth status')->andThrow(
        new ProcessTimedOutException(
            new Symfony\Component\Process\Process(['claude', 'auth', 'status']),
            ProcessTimedOutException::TYPE_GENERAL,
        )
    );

    $result = (new ClaudeAuthCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->detail)->toBe('Timed out');
});

test('health check handles Laravel-wrapped timeout exception on claude auth', function () {
    Process::shouldReceive('timeout')->with(15)->andReturnSelf();
    Process::shouldReceive('run')->with('claude auth status')->andThrow(
        new Illuminate\Process\Exceptions\ProcessTimedOutException(
            new ProcessTimedOutException(
                new Symfony\Component\Process\Process(['claude', 'auth', 'status']),
                ProcessTimedOutException::TYPE_GENERAL,
            ),
            new ProcessResult(
                new Symfony\Component\Process\Process(['claude', 'auth', 'status']),
            ),
        )
    );

    $result = (new ClaudeAuthCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->detail)->toBe('Timed out');
});

test('health check reports unhealthy when claude cli times out', function () {
    Process::shouldReceive('timeout')->with(15)->andReturnSelf();
    Process::shouldReceive('run')->with('claude --version')->andThrow(
        new ProcessTimedOutException(
            new Symfony\Component\Process\Process(['claude', '--version']),
            ProcessTimedOutException::TYPE_GENERAL,
        )
    );

    $result = (new ClaudeCliCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->detail)->toBe('Timed out');
});

test('registry includes claude auth check', function () {
    Process::fake([
        'pgrep *' => Process::result('12345'),
        '*ls-remote*' => Process::result('abc123'),
        'claude --version' => Process::result('1.0.0'),
        'claude auth status' => Process::result(
            output: '',
            errorOutput: 'Not authenticated',
            exitCode: 1,
        ),
    ]);

    $check = collect(app(Registry::class)->all())
        ->first(fn ($c) => $c->id() === 'claude-auth');

    expect($check)->not->toBeNull();

    $result = $check->run();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->detail)->toContain('not authenticated');
});
