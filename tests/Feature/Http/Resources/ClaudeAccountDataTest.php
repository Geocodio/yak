<?php

use App\Http\Resources\ClaudeAccountData;
use App\Models\ClaudeAccount;
use App\Models\Repository;

it('never carries the secret and has the documented keys', function () {
    $account = ClaudeAccount::factory()->system()->expiring(5)->create(['secret' => 'sk-ant-oat01-topsecret', 'last_error' => 'oops']);
    $repository = Repository::factory()->create(['unattended_claude_account_id' => $account->id]);

    $data = ClaudeAccountData::from($account);

    expect(array_keys($data))->toBe(['id', 'name', 'kind', 'icon', 'color', 'isSystem', 'isDefault', 'defaultModel', 'maxConcurrentTurns', 'status', 'validatedAt', 'expiresAt', 'expiresSoon', 'lastUsedAt', 'lastError', 'unattendedRepos'])
        ->and(json_encode($data))->not->toContain('topsecret')
        ->and($data['isSystem'])->toBeTrue()
        ->and($data['expiresSoon'])->toBeTrue()
        ->and($data['unattendedRepos'])->toBe([$repository->slug]);
});

it('lists no unattended repos for personal accounts', function () {
    expect(ClaudeAccountData::from(ClaudeAccount::factory()->create())['unattendedRepos'])->toBe([]);
});
