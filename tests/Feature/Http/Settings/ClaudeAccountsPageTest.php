<?php

use App\Enums\ThreadSandboxState;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->mine = ClaudeAccount::factory()->for($this->user, 'owner')->create(['validated_at' => now()]);
    $this->shared = ClaudeAccount::factory()->system()->create(['validated_at' => now()]);
});

it('returns the account lists on a partial reload and nothing else', function () {
    $version = $this->get(route('settings.claude-accounts'), ['X-Inertia' => 'true'])->headers->get('X-Inertia-Version');

    $this->get(route('settings.claude-accounts'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'Settings/ClaudeAccounts',
        'X-Inertia-Partial-Data' => 'accounts,yakAccounts',
        'X-Inertia-Version' => (string) $version,
    ])
        ->assertOk()
        ->assertJsonPath('props.accounts.0.id', $this->mine->id)
        ->assertJsonPath('props.yakAccounts.0.id', $this->shared->id)
        ->assertJsonMissingPath('props.models');
});

it('shapes the account props for a non-admin', function () {
    $this->get(route('settings.claude-accounts'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/ClaudeAccounts')
            ->where('canManageYakAccounts', false)
            ->loadDeferredProps('accounts', fn (Assert $reload) => $reload
                ->has('accounts.0', fn (Assert $account) => $account
                    ->where('id', $this->mine->id)
                    ->hasAll(['name', 'kind', 'icon', 'color', 'isSystem', 'isDefault', 'defaultModel', 'status', 'expiresAt', 'expiresSoon', 'lastUsedAt', 'lastError', 'unattendedRepos'])
                    ->missing('secret')
                    ->etc())
                ->where('yakAccounts.0.isSystem', true)));
});

it('lets an admin manage Yak accounts', function () {
    config(['claude-accounts.admin_user_ids' => [$this->user->id]]);

    $this->get(route('settings.claude-accounts'))
        ->assertInertia(fn (Assert $page) => $page->where('canManageYakAccounts', true));
});

it('never lists another person\'s personal account', function () {
    $other = ClaudeAccount::factory()->for(User::factory()->create(), 'owner')->create(['validated_at' => now()]);

    $this->get(route('settings.claude-accounts'))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('accounts', fn (Assert $reload) => $reload
            ->has('accounts', 1)
            ->where('accounts.0.id', $this->mine->id)
            ->whereNot('accounts.0.id', $other->id)));
});

it('gives the awake share to admins only', function () {
    $this->get(route('settings.claude-accounts'))->assertInertia(fn (Assert $page) => $page->where('awakeShare', null));

    config(['claude-accounts.admin_user_ids' => [$this->user->id]]);
    config(['yak.threads.person_awake_cap' => 3]);

    $other = User::factory()->create();
    Thread::factory()->private($other)->create(['sandbox_state' => ThreadSandboxState::Running]);
    Thread::factory()->create(['owner_user_id' => $other->id, 'sandbox_state' => ThreadSandboxState::Running]);
    Thread::factory()->create(['owner_user_id' => $other->id, 'sandbox_state' => ThreadSandboxState::Paused]);
    Thread::factory()->create(['owner_user_id' => $this->user->id, 'sandbox_state' => ThreadSandboxState::Running]);

    $this->get(route('settings.claude-accounts'))->assertInertia(fn (Assert $page) => $page
        ->has('awakeShare', 2)
        ->where('awakeShare.0.name', $other->name)
        ->where('awakeShare.0.awake', 2)
        ->where('awakeShare.0.cap', 3)
        ->where('awakeShare.0', fn ($row) => array_keys($row->toArray()) === ['name', 'awake', 'cap']));
});
