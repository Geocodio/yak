<?php

use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\User;
use App\Models\UserRepositorySetting;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->repo = Repository::factory()->create(['slug' => 'acme/widgets']);
    $this->resolver = new ClaudeAccountResolver;
    config(['yak.default_model' => 'opus']);
});

function resolveAccount(?User $user, ?int $chosen = null, ?string $model = null)
{
    return (new ClaudeAccountResolver)->forNewThread($user, 'acme/widgets', $chosen, $model);
}

it('prefers the chosen account', function () {
    $chosen = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $this->user->id]);
    expect(resolveAccount($this->user, $chosen->id)->account->id)->toBe($chosen->id);
});

it('allows a chosen system account', function () {
    $system = ClaudeAccount::factory()->system()->create();
    expect(resolveAccount($this->user, $system->id)->account->id)->toBe($system->id);
});

it('ignores a chosen account of another person', function () {
    $foreign = ClaudeAccount::factory()->create();
    $own = ClaudeAccount::factory()->default()->create(['owner_user_id' => $this->user->id]);
    expect(resolveAccount($this->user, $foreign->id)->account->id)->toBe($own->id);
});

it('falls through the ladder one rung at a time', function () {
    $global = ClaudeAccount::factory()->system()->default()->create();
    expect(resolveAccount($this->user)->account->id)->toBe($global->id);

    $unattended = ClaudeAccount::factory()->system()->create();
    $this->repo->update(['unattended_claude_account_id' => $unattended->id]);
    expect(resolveAccount($this->user)->account->id)->toBe($unattended->id)
        ->and(resolveAccount(null)->account->id)->toBe($unattended->id);

    $default = ClaudeAccount::factory()->default()->create(['owner_user_id' => $this->user->id]);
    expect(resolveAccount($this->user)->account->id)->toBe($default->id);

    $setting = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);
    UserRepositorySetting::factory()->create(['user_id' => $this->user->id, 'repo' => 'acme/widgets', 'claude_account_id' => $setting->id]);
    expect(resolveAccount($this->user)->account->id)->toBe($setting->id);

    $chosen = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);
    expect(resolveAccount($this->user, $chosen->id)->account->id)->toBe($chosen->id);
});

it('skips unusable candidates at each rung', function () {
    $global = ClaudeAccount::factory()->system()->default()->create();
    $bad = ClaudeAccount::factory()->invalid()->create(['owner_user_id' => $this->user->id, 'is_default' => true]);
    UserRepositorySetting::factory()->create(['user_id' => $this->user->id, 'repo' => 'acme/widgets', 'claude_account_id' => ClaudeAccount::factory()->expired()->create(['owner_user_id' => $this->user->id])->id]);
    $unattended = ClaudeAccount::factory()->system()->invalid()->create();
    $this->repo->update(['unattended_claude_account_id' => $unattended->id]);

    expect(resolveAccount($this->user, $bad->id)->account->id)->toBe($global->id);
});

it('returns a null account with a model when nothing is usable', function () {
    $result = resolveAccount($this->user);
    expect($result->account)->toBeNull()->and($result->model)->toBe('opus');
});

it('orders the model: chosen, repo setting, account default, config', function () {
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id, 'default_model' => 'haiku']);
    $account->markDefaultFor($this->user);
    expect(resolveAccount($this->user)->model)->toBe('haiku')
        ->and(resolveAccount($this->user, null, 'sonnet')->model)->toBe('sonnet');

    UserRepositorySetting::factory()->create(['user_id' => $this->user->id, 'repo' => 'acme/widgets', 'claude_account_id' => $account->id, 'model' => 'opus']);
    expect(resolveAccount($this->user)->model)->toBe('opus')
        ->and(resolveAccount($this->user, null, 'sonnet')->model)->toBe('sonnet');

    $account->update(['default_model' => null]);
    UserRepositorySetting::query()->update(['model' => null]);
    expect(resolveAccount($this->user)->model)->toBe('opus');
});

it('does not apply the repo setting model when another account is used', function () {
    $setting = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);
    UserRepositorySetting::factory()->create(['user_id' => $this->user->id, 'repo' => 'acme/widgets', 'claude_account_id' => $setting->id, 'model' => 'haiku']);
    $chosen = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id, 'default_model' => 'sonnet']);

    expect(resolveAccount($this->user, $chosen->id)->model)->toBe('sonnet');
});

it('applies the repo setting model whenever the setting account is the account used', function (string $via) {
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id, 'default_model' => 'sonnet']);
    if ($via === 'default') {
        $account->markDefaultFor($this->user);
    }
    UserRepositorySetting::factory()->create(['user_id' => $this->user->id, 'repo' => 'acme/widgets', 'claude_account_id' => $account->id, 'model' => 'haiku']);

    $chosenId = $via === 'chosen' ? $account->id : null;

    expect(resolveAccount($this->user, $chosenId)->model)->toBe('haiku');
})->with(['chosen', 'default']);
