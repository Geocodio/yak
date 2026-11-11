<?php

use App\DataTransferObjects\AccountValidation;
use App\Enums\ClaudeAccountKind;
use App\Models\ClaudeAccount;
use App\Services\ClaudeAccounts\ClaudeAccountValidator;
use App\Services\Threads\AuthProxyPlaceholders;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeout;
use Symfony\Component\Process\Process as SymfonyProcess;

const VALIDATION_SECRET = 'sk-ant-oat01-supersecretvalue1234567890';

beforeEach(function () {
    $this->placeholders = Mockery::mock(AuthProxyPlaceholders::class);
    $this->placeholders->shouldReceive('issue')->andReturnUsing(function ($subject, ClaudeAccount $account, $ip) {
        $this->issued = compact('subject', 'ip') + ['secretAtIssue' => $account->secret, 'rowExists' => ClaudeAccount::query()->whereKey($account->id)->exists()];

        return 'yakph-placeholder';
    });
    $this->placeholders->shouldReceive('revoke')->once()->andReturnUsing(function ($subject) {
        $this->revoked = $subject;
    });
    app()->instance(AuthProxyPlaceholders::class, $this->placeholders);
});

function validate(): AccountValidation
{
    return app(ClaudeAccountValidator::class)->validate(ClaudeAccountKind::SetupToken, VALIDATION_SECRET);
}

it('accepts a credential when the Haiku turn succeeds', function () {
    Process::fake(['*' => Process::result('{"is_error":false,"result":"OK"}')]);

    expect(validate()->valid)->toBeTrue();
    expect($this->issued['ip'])->toBe('127.0.0.1')
        ->and($this->issued['rowExists'])->toBeTrue()
        ->and($this->revoked)->toBe($this->issued['subject']);
    expect(ClaudeAccount::query()->count())->toBe(0);
});

it('rejects an is_error result', function () {
    Process::fake(['*' => Process::result('{"is_error":true,"result":"Invalid API key, 401"}')]);

    $result = validate();

    expect($result->valid)->toBeFalse()->and($result->error)->toContain('401');
});

it('rejects a non-zero exit', function () {
    Process::fake(['*' => Process::result('', 'boom', 1)]);

    expect(validate()->valid)->toBeFalse();
    expect(ClaudeAccount::query()->count())->toBe(0);
});

it('reports unreachable Claude on a timeout and cleans up', function () {
    Process::fake(['*' => fn () => throw new ProcessTimedOutException(
        new SymfonyTimeout(new SymfonyProcess(['claude']), SymfonyTimeout::TYPE_GENERAL),
        Process::result(),
    )]);

    $result = validate();

    expect($result->valid)->toBeFalse()->and($result->error)->toContain('could not reach Claude');
    expect(ClaudeAccount::query()->count())->toBe(0);
});

it('never echoes the secret in the error', function () {
    Process::fake(['*' => Process::result('{"is_error":true,"result":"bad credential ' . VALIDATION_SECRET . ' end"}')]);

    $result = validate();

    expect($result->error)->not->toContain(VALIDATION_SECRET)->toContain('[redacted]');
});

it('truncates long errors to 300 characters', function () {
    Process::fake(['*' => Process::result('{"is_error":true,"result":"' . str_repeat('x', 900) . '"}')]);

    expect(mb_strlen(validate()->error))->toBe(300);
});

it('runs with a cleared environment and only the placeholder', function () {
    putenv('YAK_LEAK_CANARY=leak');
    Process::fake(['*' => Process::result('{"is_error":false}')]);
    $homes = [];

    validate();

    Process::assertRan(function ($process) use (&$homes) {
        $env = $process->environment;
        $homes = [$env['HOME'], $env['CLAUDE_CONFIG_DIR']];

        return ($env['YAK_LEAK_CANARY'] ?? null) === false
            && $env['CLAUDE_CODE_OAUTH_TOKEN'] === 'yakph-placeholder'
            && ! in_array(VALIDATION_SECRET, array_values(array_filter($env, 'is_string')), true)
            && $process->command === ['claude', '-p', '--model', 'haiku', '--max-turns', '1', '--output-format', 'json', 'Reply with OK'];
    });
    foreach ($homes as $dir) {
        expect(is_dir($dir))->toBeFalse();
    }
    putenv('YAK_LEAK_CANARY');
});
