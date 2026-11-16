<?php

use App\Enums\ClaudeAccountColor;
use App\Enums\ClaudeAccountIcon;
use App\Enums\ClaudeAccountStatus;
use App\Models\ClaudeAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('has appearance defaults and system leaves no owner', function () {
    $account = ClaudeAccount::factory()->create()->refresh();
    expect($account->icon)->toBe(ClaudeAccountIcon::Briefcase)
        ->and($account->color)->toBe(ClaudeAccountColor::Violet)
        ->and(ClaudeAccount::factory()->system()->create()->owner_user_id)->toBeNull();
});

it('encrypts the secret and hides it', function () {
    $account = ClaudeAccount::factory()->create(['secret' => 'sk-ant-oat01-plain']);

    expect(DB::table('claude_accounts')->where('id', $account->id)->value('secret'))->not->toBe('sk-ant-oat01-plain')
        ->and($account->fresh()->secret)->toBe('sk-ant-oat01-plain')
        ->and($account->toArray())->not->toHaveKey('secret')
        ->and($account->toJson())->not->toContain('sk-ant-oat01-plain');
});

it('decides usability', function (ClaudeAccount $account, bool $usable) {
    expect($account->isUsable())->toBe($usable);
})->with([
    'active' => [fn () => ClaudeAccount::factory()->make(), true],
    'invalid' => [fn () => ClaudeAccount::factory()->invalid()->make(), false],
    'expired' => [fn () => ClaudeAccount::factory()->expired()->make(), false],
    'future expiry' => [fn () => ClaudeAccount::factory()->make(['expires_at' => now()->addYear()]), true],
    'past expiry but active' => [fn () => ClaudeAccount::factory()->make(['expires_at' => now()->subDay()]), false],
]);

it('keeps one default per owner and one global system default', function () {
    $user = User::factory()->create();
    $a = ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id]);
    $b = ClaudeAccount::factory()->create(['owner_user_id' => $user->id]);
    $other = ClaudeAccount::factory()->default()->create();
    $b->markDefaultFor($user);

    expect($a->fresh()->is_default)->toBeFalse()->and($b->fresh()->is_default)->toBeTrue()->and($other->fresh()->is_default)->toBeTrue();

    $s1 = ClaudeAccount::factory()->system()->default()->create();
    $s2 = ClaudeAccount::factory()->system()->create();
    $s2->markDefaultFor(null);

    expect($s1->fresh()->is_default)->toBeFalse()->and(ClaudeAccount::globalSystemAccount()?->id)->toBe($s2->id);
});

it('records invalid, expired and used', function () {
    $account = ClaudeAccount::factory()->create();
    $account->markUsed();
    expect($account->fresh()->last_used_at)->not->toBeNull();
    $account->markInvalid('bad token');
    expect($account->fresh()->status)->toBe(ClaudeAccountStatus::Invalid)->and($account->fresh()->last_error)->toBe('bad token');
    $account->markExpired();
    expect($account->fresh()->status)->toBe(ClaudeAccountStatus::Expired);
});

it('reports credential shape and expiry warning', function () {
    expect(ClaudeAccount::factory()->make(['secret' => 'x'])->credential())->toBe(['type' => 'oauth_token', 'value' => 'x'])
        ->and(ClaudeAccount::factory()->apiKey()->make(['secret' => 'k'])->credential())->toBe(['type' => 'api_key', 'value' => 'k'])
        ->and(ClaudeAccount::factory()->expiring(10)->make()->expiresSoon())->toBeTrue()
        ->and(ClaudeAccount::factory()->expiring(30)->make()->expiresSoon())->toBeFalse()
        ->and(ClaudeAccount::factory()->make()->expiresSoon())->toBeFalse();
});

it('scopes checked accounts', function () {
    ClaudeAccount::factory()->create();
    $checked = ClaudeAccount::factory()->create(['validated_at' => now()]);
    expect(ClaudeAccount::query()->checked()->pluck('id')->all())->toBe([$checked->id]);
});
