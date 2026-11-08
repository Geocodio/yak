<?php

use App\DataTransferObjects\AccountValidation;
use App\Enums\ClaudeAccountKind;
use App\Models\ClaudeAccount;
use App\Services\ClaudeAccounts\ClaudeAccountValidator;

function secretFile(string $secret): string
{
    $path = tempnam(sys_get_temp_dir(), 'yak-secret');
    file_put_contents($path, $secret . "\n");

    return $path;
}

function validatorReturning(bool $valid): void
{
    $validator = Mockery::mock(ClaudeAccountValidator::class);
    $validator->shouldReceive('validate')->andReturn(new AccountValidation($valid, $valid ? null : 'Invalid API key'));
    app()->instance(ClaudeAccountValidator::class, $validator);
}

it('adds a validated system account from the secret file and makes the first one the default', function () {
    validatorReturning(true);
    $path = secretFile('sk-ant-oat01-shared');

    $this->artisan('yak:claude-account:add-system', ['name' => 'Yak shared', '--from' => $path])->assertSuccessful();

    $account = ClaudeAccount::query()->sole();
    expect($account->owner_user_id)->toBeNull()
        ->and($account->secret)->toBe('sk-ant-oat01-shared')
        ->and($account->kind)->toBe(ClaudeAccountKind::SetupToken)
        ->and($account->is_default)->toBeTrue()
        ->and(ClaudeAccount::globalSystemAccount()?->is($account))->toBeTrue();
    unlink($path);
});

it('does not take the default from an existing system account unless asked', function () {
    validatorReturning(true);
    $existing = ClaudeAccount::factory()->system()->create(['is_default' => true]);
    $path = secretFile('sk-ant-api03-key');

    $this->artisan('yak:claude-account:add-system', ['name' => 'Second', '--kind' => 'api_key', '--from' => $path])->assertSuccessful();
    expect($existing->fresh()->is_default)->toBeTrue();

    $this->artisan('yak:claude-account:add-system', ['name' => 'Third', '--from' => $path, '--default' => true])->assertSuccessful();
    expect($existing->fresh()->is_default)->toBeFalse();
    unlink($path);
});

it('saves nothing when Claude rejects the credential', function () {
    validatorReturning(false);
    $path = secretFile('bad');

    $this->artisan('yak:claude-account:add-system', ['name' => 'Bad', '--from' => $path])->assertFailed();

    expect(ClaudeAccount::query()->count())->toBe(0);
    unlink($path);
});

it('refuses an unknown kind and an empty secret', function () {
    $this->artisan('yak:claude-account:add-system', ['name' => 'Shared', '--kind' => 'nope', '--from' => secretFile('x')])->assertFailed();
    $this->artisan('yak:claude-account:add-system', ['name' => 'Shared', '--from' => secretFile('')])->assertFailed();

    expect(ClaudeAccount::query()->count())->toBe(0);
});
