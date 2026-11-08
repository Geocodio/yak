<?php

use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\User;
use App\Models\UserRepositorySetting;
use App\Models\YakTask;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;

function startedTask(User $user, array $attributes = []): YakTask
{
    return YakTask::factory()->create(['repo' => 'acme', 'started_by_user_id' => $user->id, ...$attributes]);
}

it("uses the starter's choice", function () {
    $user = User::factory()->create();
    $chosen = ClaudeAccount::factory()->create(['owner_user_id' => $user->id]);
    ClaudeAccount::factory()->create(['owner_user_id' => $user->id, 'is_default' => true]);

    $resolved = app(ClaudeAccountResolver::class)
        ->forNewThread(startedTask($user, ['requested_claude_account_id' => $chosen->id]));

    expect($resolved->account->is($chosen))->toBeTrue();
});

it('accepts a system account as the choice', function () {
    $user = User::factory()->create();
    $system = ClaudeAccount::factory()->system()->create();

    $resolved = app(ClaudeAccountResolver::class)
        ->forNewThread(startedTask($user, ['requested_claude_account_id' => $system->id]));

    expect($resolved->account->is($system))->toBeTrue();
});

it('ignores a chosen account owned by someone else', function () {
    $user = User::factory()->create();
    $theirs = ClaudeAccount::factory()->create();
    $default = ClaudeAccount::factory()->create(['owner_user_id' => $user->id, 'is_default' => true]);

    $resolved = app(ClaudeAccountResolver::class)
        ->forNewThread(startedTask($user, ['requested_claude_account_id' => $theirs->id]));

    expect($resolved->account->is($default))->toBeTrue();
});

it('falls back to the repo default then the overall default', function () {
    $user = User::factory()->create();
    $overall = ClaudeAccount::factory()->create(['owner_user_id' => $user->id, 'is_default' => true]);
    $forRepo = ClaudeAccount::factory()->create(['owner_user_id' => $user->id]);
    $resolver = app(ClaudeAccountResolver::class);

    expect($resolver->forNewThread(startedTask($user))->account->is($overall))->toBeTrue();

    UserRepositorySetting::factory()->create(['user_id' => $user->id, 'repo' => 'acme', 'claude_account_id' => $forRepo->id]);

    expect($resolver->forNewThread(startedTask($user))->account->is($forRepo))->toBeTrue();
});

it('skips an unusable repo default', function () {
    $user = User::factory()->create();
    $overall = ClaudeAccount::factory()->create(['owner_user_id' => $user->id, 'is_default' => true]);
    $expired = ClaudeAccount::factory()->expired()->create(['owner_user_id' => $user->id]);
    UserRepositorySetting::factory()->create(['user_id' => $user->id, 'repo' => 'acme', 'claude_account_id' => $expired->id]);

    expect(app(ClaudeAccountResolver::class)->forNewThread(startedTask($user))->account->is($overall))->toBeTrue();
});

it("runs unattended tasks on the repo's unattended account", function () {
    $unattended = ClaudeAccount::factory()->system()->create();
    ClaudeAccount::factory()->system()->create(['is_default' => true]);
    Repository::factory()->create(['slug' => 'acme', 'unattended_claude_account_id' => $unattended->id]);
    $task = YakTask::factory()->create(['repo' => 'acme', 'started_by_user_id' => null]);

    expect(app(ClaudeAccountResolver::class)->forNewThread($task)->account->is($unattended))->toBeTrue();
});

it('runs a starter with no usable account on the global system account', function () {
    $user = User::factory()->create();
    ClaudeAccount::factory()->invalid()->create(['owner_user_id' => $user->id, 'is_default' => true]);
    $global = ClaudeAccount::factory()->system()->create(['is_default' => true]);

    expect(app(ClaudeAccountResolver::class)->forNewThread(startedTask($user))->account->is($global))->toBeTrue();
});

it('returns no account when nothing is usable', function () {
    $user = User::factory()->create();
    ClaudeAccount::factory()->system()->invalid()->create(['is_default' => true]);

    $resolved = app(ClaudeAccountResolver::class)->forNewThread(startedTask($user));

    expect($resolved->account)->toBeNull()
        ->and($resolved->model)->toBe(config('yak.default_model'));
});

it('picks the model in the documented order', function () {
    $user = User::factory()->create();
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $user->id, 'is_default' => true, 'default_model' => 'sonnet']);
    $resolver = app(ClaudeAccountResolver::class);

    expect($resolver->forNewThread(startedTask($user))->model)->toBe('sonnet');

    UserRepositorySetting::factory()->create([
        'user_id' => $user->id, 'repo' => 'acme', 'claude_account_id' => $account->id, 'model' => 'haiku',
    ]);

    expect($resolver->forNewThread(startedTask($user))->model)->toBe('haiku')
        ->and($resolver->forNewThread(startedTask($user, ['requested_model' => 'opus']))->model)->toBe('opus');
});
