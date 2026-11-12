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

it('lets an admin change the unattended account and a member not', function () {
    $this->get(route('repos.edit', $this->repository))
        ->assertInertia(fn (Assert $page) => $page->where('claudeAccounts.canChangeUnattended', false));

    config(['claude-accounts.admin_user_ids' => [$this->user->id]]);

    $this->get(route('repos.edit', $this->repository))
        ->assertInertia(fn (Assert $page) => $page->where('claudeAccounts.canChangeUnattended', true));
});

it('flags a chosen account that is no longer active', function () {
    $broken = ClaudeAccount::factory()->for($this->user, 'owner')->expired()->create(['validated_at' => now()]);
    UserRepositorySetting::query()->create(['user_id' => $this->user->id, 'repo' => $this->repository->slug, 'claude_account_id' => $broken->id]);

    $this->get(route('repos.edit', $this->repository))
        ->assertInertia(fn (Assert $page) => $page
            ->where('claudeAccounts.mySetting.accountId', $broken->id)
            ->where('claudeAccounts.accounts.0.id', $broken->id)
            ->where('claudeAccounts.accounts.0.usable', false)
            ->where('claudeAccounts.accounts.0.status', 'expired'));
});

it('never exposes another person account or a secret', function () {
    $mine = ClaudeAccount::factory()->for($this->user, 'owner')->create(['validated_at' => now()]);
    $theirs = ClaudeAccount::factory()->for(User::factory()->create(), 'owner')->create(['validated_at' => now()]);

    $this->get(route('repos.edit', $this->repository))
        ->assertInertia(fn (Assert $page) => $page
            ->has('claudeAccounts.accounts', 1)
            ->where('claudeAccounts.accounts.0.id', $mine->id)
            ->whereNot('claudeAccounts.accounts.0.id', $theirs->id)
            ->missing('claudeAccounts.accounts.0.secret')
            ->has('claudeAccounts.docsUrl'));
});

it('does not render the section on the add page', function () {
    $this->get(route('repos.create'))
        ->assertInertia(fn (Assert $page) => $page->missing('claudeAccounts'));
});
