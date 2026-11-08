<?php

use App\Models\ClaudeAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('never serializes the secret', function () {
    $account = ClaudeAccount::factory()->create(['secret' => 'top-secret-value']);

    expect($account->toArray())->not->toHaveKey('secret');
    expect($account->toJson())->not->toContain('top-secret-value')->not->toContain('secret');
});

it('stores the secret encrypted', function () {
    $account = ClaudeAccount::factory()->create(['secret' => 'top-secret-value']);

    $raw = DB::table('claude_accounts')->where('id', $account->id)->value('secret');

    expect($raw)->not->toBe('top-secret-value')->not->toContain('top-secret-value');
    expect($account->fresh()->secret)->toBe('top-secret-value');
});

it('keeps one default per owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $first = ClaudeAccount::factory()->for($owner, 'owner')->create();
    $second = ClaudeAccount::factory()->for($owner, 'owner')->create();
    $othersDefault = ClaudeAccount::factory()->for($other, 'owner')->create(['is_default' => true]);

    $first->markDefaultFor($owner);
    $second->markDefaultFor($owner);

    expect($first->fresh()->is_default)->toBeFalse();
    expect($second->fresh()->is_default)->toBeTrue();
    expect($othersDefault->fresh()->is_default)->toBeTrue();
});

it('keeps one global system account', function () {
    $first = ClaudeAccount::factory()->system()->create();
    $second = ClaudeAccount::factory()->system()->create();

    expect(ClaudeAccount::globalSystemAccount())->toBeNull();

    $first->markDefaultFor(null);
    $second->markDefaultFor(null);

    expect($second->isSystem())->toBeTrue();
    expect(ClaudeAccount::globalSystemAccount()->is($second))->toBeTrue();
    expect(ClaudeAccount::query()->where('is_default', true)->count())->toBe(1);
});

it('is not usable when expired', function () {
    expect(ClaudeAccount::factory()->create()->isUsable())->toBeTrue();
    expect(ClaudeAccount::factory()->expired()->create()->isUsable())->toBeFalse();
    expect(ClaudeAccount::factory()->invalid()->create()->isUsable())->toBeFalse();
});

it('builds an oauth credential for a setup token and an api key credential for a key', function () {
    $token = ClaudeAccount::factory()->create(['secret' => 'tok']);
    $key = ClaudeAccount::factory()->apiKey()->create(['secret' => 'key']);

    expect($token->credential())->toBe(['type' => 'oauth_token', 'value' => 'tok']);
    expect($key->credential())->toBe(['type' => 'api_key', 'value' => 'key']);
});
