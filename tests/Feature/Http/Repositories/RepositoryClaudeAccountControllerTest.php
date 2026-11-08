<?php

use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\User;
use App\Models\UserRepositorySetting;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->repository = Repository::factory()->create(['slug' => 'acme/web']);
});

it('saves my account for a repo', function () {
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);

    $this->put(route('repos.claude-account.update', $this->repository), ['claude_account_id' => $account->id, 'model' => 'sonnet'])
        ->assertRedirect();

    $setting = UserRepositorySetting::query()->where('user_id', $this->user->id)->where('repo', 'acme/web')->firstOrFail();
    expect($setting->claude_account_id)->toBe($account->id)->and($setting->model)->toBe('sonnet');
});

it('lets me pick a system account as my repo default', function () {
    $system = ClaudeAccount::factory()->system()->create();

    $this->put(route('repos.claude-account.update', $this->repository), ['claude_account_id' => $system->id])->assertRedirect();

    expect(UserRepositorySetting::query()->firstOrFail()->claude_account_id)->toBe($system->id);
});

it('clears my repo default when no account is sent', function () {
    UserRepositorySetting::factory()->create([
        'user_id' => $this->user->id,
        'repo' => 'acme/web',
        'claude_account_id' => ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id])->id,
    ]);

    $this->put(route('repos.claude-account.update', $this->repository), ['claude_account_id' => null])->assertRedirect();

    expect(UserRepositorySetting::query()->count())->toBe(0);
});

it("rejects someone else's account as my repo default", function () {
    $theirs = ClaudeAccount::factory()->create();

    $this->put(route('repos.claude-account.update', $this->repository), ['claude_account_id' => $theirs->id])
        ->assertSessionHasErrors('claude_account_id');

    expect(UserRepositorySetting::query()->count())->toBe(0);
});

it('rejects an unknown model', function () {
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);

    $this->put(route('repos.claude-account.update', $this->repository), ['claude_account_id' => $account->id, 'model' => 'gpt'])
        ->assertSessionHasErrors('model');
});

it('only accepts system accounts as the unattended account', function () {
    $system = ClaudeAccount::factory()->system()->create();
    $mine = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);

    $this->put(route('repos.unattended-claude-account.update', $this->repository), ['claude_account_id' => $mine->id])
        ->assertSessionHasErrors('claude_account_id');
    expect($this->repository->fresh()->unattended_claude_account_id)->toBeNull();

    $this->put(route('repos.unattended-claude-account.update', $this->repository), ['claude_account_id' => $system->id])->assertRedirect();
    expect($this->repository->fresh()->unattended_claude_account_id)->toBe($system->id);

    $this->put(route('repos.unattended-claude-account.update', $this->repository), ['claude_account_id' => null])->assertRedirect();
    expect($this->repository->fresh()->unattended_claude_account_id)->toBeNull();
});

it('sends the account picker props to the repo edit page', function () {
    $mine = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);
    ClaudeAccount::factory()->create();
    UserRepositorySetting::factory()->create(['user_id' => $this->user->id, 'repo' => 'acme/web', 'claude_account_id' => $mine->id, 'model' => 'haiku']);

    $this->get(route('repos.edit', $this->repository))->assertInertia(fn (Assert $page) => $page
        ->has('accountPicker.accounts', 1)
        ->where('accountPicker.defaultsByRepo.acme/web', ['accountId' => $mine->id, 'model' => 'haiku'])
        ->where('accountPicker.models', ['opus', 'sonnet', 'haiku'])
        ->where('unattendedClaudeAccountId', null));
});
