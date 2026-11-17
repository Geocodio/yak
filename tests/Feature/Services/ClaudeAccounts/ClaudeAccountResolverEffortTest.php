<?php

use App\Models\ClaudeAccount;
use App\Models\User;
use App\Models\UserRepositorySetting;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = ClaudeAccount::factory()->for($this->user, 'owner')->create(['validated_at' => now(), 'is_default' => true, 'default_effort' => 'low']);
});

it('resolves effort from the choice, then the repository setting, then the account default', function () {
    $resolver = app(ClaudeAccountResolver::class);
    UserRepositorySetting::factory()->create(['user_id' => $this->user->id, 'repo' => 'acme/api', 'claude_account_id' => $this->account->id, 'effort' => 'medium']);

    expect($resolver->forNewThread($this->user, 'acme/api', null, null, 'max')->effort)->toBe('max')
        ->and($resolver->forNewThread($this->user, 'acme/api', null, null)->effort)->toBe('medium')
        ->and($resolver->forNewThread($this->user, 'acme/other', null, null)->effort)->toBe('low');
});

it('ignores the repository setting effort when another account is chosen', function () {
    $other = ClaudeAccount::factory()->for($this->user, 'owner')->create(['validated_at' => now(), 'default_effort' => null]);
    UserRepositorySetting::factory()->create(['user_id' => $this->user->id, 'repo' => 'acme/api', 'claude_account_id' => $this->account->id, 'effort' => 'medium']);

    expect(app(ClaudeAccountResolver::class)->forNewThread($this->user, 'acme/api', $other->id, null)->effort)->toBeNull();
});
