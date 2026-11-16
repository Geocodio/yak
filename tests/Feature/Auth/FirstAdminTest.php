<?php

use App\Models\User;
use App\Policies\ClaudeAccountPolicy;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

function signInWithGoogle(string $email): void
{
    config(['services.google.allowed_domains' => 'example.com']);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->map(['id' => $email, 'name' => 'Someone', 'email' => $email]);
    Socialite::fake('google', $socialiteUser);

    test()->get(route('auth.google.callback'));
}

beforeEach(function () {
    config(['claude-accounts.admin_user_ids' => []]);
});

it('makes the first user to sign in an admin', function () {
    signInWithGoogle('first@example.com');

    expect(User::query()->where('email', 'first@example.com')->firstOrFail()->is_admin)->toBeTrue();
});

it('does not make a second user an admin', function () {
    signInWithGoogle('first@example.com');
    auth()->logout();
    signInWithGoogle('second@example.com');

    expect(User::query()->where('email', 'second@example.com')->firstOrFail()->is_admin)->toBeFalse();
});

it('does not auto-promote when admins are configured by id', function () {
    config(['claude-accounts.admin_user_ids' => [999]]);

    signInWithGoogle('first@example.com');

    expect(User::query()->where('email', 'first@example.com')->firstOrFail()->is_admin)->toBeFalse();
});

it('lets admins and config-listed users create system accounts', function () {
    $policy = new ClaudeAccountPolicy;
    $admin = User::factory()->admin()->create();
    $listed = User::factory()->create();
    $member = User::factory()->create();
    config(['claude-accounts.admin_user_ids' => [$listed->id]]);

    expect($policy->create($admin, true))->toBeTrue()
        ->and($policy->create($listed, true))->toBeTrue()
        ->and($policy->create($member, true))->toBeFalse()
        ->and($policy->create($member))->toBeTrue();
});

it('scopes admins to the flag or the config list', function () {
    $flagged = User::factory()->admin()->create();
    $listed = User::factory()->create();
    User::factory()->create();
    config(['claude-accounts.admin_user_ids' => [$listed->id]]);

    expect(User::query()->admins()->pluck('id')->all())->toEqualCanonicalizing([$flagged->id, $listed->id])
        ->and($listed->isAdminByConfig())->toBeTrue()
        ->and($flagged->isAdminByConfig())->toBeFalse();
});

it('does not make a new user an admin when other users already exist', function () {
    User::factory()->create();

    signInWithGoogle('late@example.com');

    expect(User::query()->where('email', 'late@example.com')->firstOrFail()->is_admin)->toBeFalse();
});
