<?php

use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\User;
use App\Models\UserRepositorySetting;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->repository = Repository::factory()->create();
});

function checkedAccount(array $state = [], ?User $owner = null): ClaudeAccount
{
    $factory = $owner === null ? ClaudeAccount::factory()->system() : ClaudeAccount::factory()->for($owner, 'owner');

    return $factory->create(['validated_at' => now(), ...$state]);
}

it('upserts and clears my setting', function () {
    $mine = checkedAccount(owner: $this->user);

    $this->put(route('repos.claude-account.update', $this->repository), ['claude_account_id' => $mine->id, 'model' => 'haiku'])->assertRedirect();
    $this->put(route('repos.claude-account.update', $this->repository), ['claude_account_id' => $mine->id, 'model' => 'opus']);

    expect(UserRepositorySetting::query()->count())->toBe(1)
        ->and(UserRepositorySetting::query()->first()->model)->toBe('opus');

    $this->put(route('repos.claude-account.update', $this->repository), ['claude_account_id' => null]);
    expect(UserRepositorySetting::query()->count())->toBe(0);
});

it('lets me pick a system account but not another person account', function () {
    $system = checkedAccount();
    $theirs = checkedAccount(owner: User::factory()->create());

    $this->put(route('repos.claude-account.update', $this->repository), ['claude_account_id' => $theirs->id])->assertSessionHasErrors('claude_account_id');
    $this->put(route('repos.claude-account.update', $this->repository), ['claude_account_id' => $system->id])->assertSessionHasNoErrors();
});

it('rejects an unusable account', function () {
    $expired = ClaudeAccount::factory()->for($this->user, 'owner')->expired()->create(['validated_at' => now()]);

    $this->put(route('repos.claude-account.update', $this->repository), ['claude_account_id' => $expired->id])->assertSessionHasErrors('claude_account_id');
});

it('sets the unattended account for admins and system accounts only', function () {
    $system = checkedAccount();
    $mine = checkedAccount(owner: $this->user);

    $this->put(route('repos.unattended-claude-account.update', $this->repository), ['claude_account_id' => $system->id])->assertForbidden();

    config(['claude-accounts.admin_user_ids' => [$this->user->id]]);
    $this->put(route('repos.unattended-claude-account.update', $this->repository), ['claude_account_id' => $mine->id])->assertSessionHasErrors('claude_account_id');
    $this->put(route('repos.unattended-claude-account.update', $this->repository), ['claude_account_id' => $system->id])->assertSessionHasNoErrors();

    expect($this->repository->fresh()->unattended_claude_account_id)->toBe($system->id);
});

it('shares the repository section props on the edit page', function () {
    $system = checkedAccount();
    $this->repository->forceFill(['unattended_claude_account_id' => $system->id])->save();

    $this->get(route('repos.edit', $this->repository))->assertInertia(fn (Assert $page) => $page
        ->component('Repositories/Form')
        ->where('claudeAccounts.unattendedAccountId', $system->id)
        ->where('claudeAccounts.canChangeUnattended', false)
        ->has('claudeAccounts.accounts', 1)
        ->where('claudeAccounts.mySetting', ['accountId' => null, 'model' => null]));
});
