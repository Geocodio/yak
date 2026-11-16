<?php

use App\Enums\SshKeySource;
use App\Models\GithubKeySync;
use App\Models\SshKey;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

it('refuses a second row with the same fingerprint', function () {
    $first = SshKey::factory()->create();

    SshKey::factory()->create(['fingerprint' => $first->fingerprint]);
})->throws(UniqueConstraintViolationException::class);

it('deletes keys and the sync row with the user', function () {
    $user = User::factory()->create();
    SshKey::factory()->forUser($user)->create();
    GithubKeySync::factory()->forUser($user)->create();

    $user->delete();

    expect(SshKey::count())->toBe(0)->and(GithubKeySync::count())->toBe(0);
});

it('builds the factory states and scopes', function () {
    $user = User::factory()->create();
    SshKey::factory()->forUser($user)->create();
    SshKey::factory()->forUser($user)->fromGithub()->create();

    expect($user->sshKeys()->uploaded()->count())->toBe(1)
        ->and($user->sshKeys()->fromGithub()->first()->source)->toBe(SshKeySource::Github)
        ->and($user->sshKeys()->fromGithub()->first()->github_added_at)->not->toBeNull()
        ->and(GithubKeySync::factory()->forUser($user)->errored()->create()->status)->toBe('error')
        ->and($user->fresh()->githubKeySync->user_id)->toBe($user->id);
});
