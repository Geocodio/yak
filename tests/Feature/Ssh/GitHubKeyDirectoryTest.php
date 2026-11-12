<?php

use App\Models\GithubKeySync;
use App\Models\SshKey;
use App\Models\User;
use App\Services\Ssh\GitHubKeyDirectory;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

function ed25519Line(): string
{
    return 'ssh-ed25519 ' . base64_encode(pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . random_bytes(32));
}

function githubUser(): User
{
    return User::factory()->create(['github_id' => 4242, 'github_login' => 'old-name']);
}

function fakeGitHub(string $keysBody = '', string $login = 'dana', int $keysStatus = 200): void
{
    Http::swap(new Factory);
    Http::fake([
        'api.github.com/user/*' => Http::response(['login' => $login]),
        "github.com/{$login}.keys" => Http::response($keysBody, $keysStatus),
    ]);
}

function directory(): GitHubKeyDirectory
{
    return app(GitHubKeyDirectory::class);
}

it('makes no http call while the cache is fresh', function () {
    $user = githubUser();
    fakeGitHub(ed25519Line());

    directory()->refresh($user);
    directory()->refresh($user);

    Http::assertSentCount(2);
});

it('bypasses the cache when forced', function () {
    $user = githubUser();
    fakeGitHub(ed25519Line());

    directory()->refresh($user);
    directory()->refresh($user, true);

    Http::assertSentCount(4);
});

it('refreshes again once the positive ttl has passed', function () {
    $user = githubUser();
    fakeGitHub(ed25519Line());

    directory()->refresh($user);
    $this->travel(301)->seconds();
    directory()->refresh($user);

    Http::assertSentCount(4);
});

it('caches an account without keys negatively for fifteen minutes', function () {
    $user = githubUser();
    fakeGitHub('');

    expect(directory()->refresh($user)->status)->toBe('empty');
    $this->travel(600)->seconds();
    directory()->refresh($user);
    Http::assertSentCount(2);

    $this->travel(301)->seconds();
    directory()->refresh($user);
    Http::assertSentCount(4);
});

it('caches an unknown account negatively', function () {
    $user = githubUser();
    Http::fake(['api.github.com/user/*' => Http::response([], 404)]);

    expect(directory()->refresh($user)->status)->toBe('unknown_account');
    directory()->refresh($user);

    Http::assertSentCount(1);
});

it('follows a renamed login through the numeric id', function () {
    $user = githubUser();
    fakeGitHub(ed25519Line(), 'new-name');

    $sync = directory()->refresh($user);

    expect($sync->github_login)->toBe('new-name');
    Http::assertSent(fn ($request) => $request->url() === 'https://github.com/new-name.keys');
});

it('keeps stale keys and flags them when GitHub errors', function () {
    $user = githubUser();
    fakeGitHub(ed25519Line());
    directory()->refresh($user);

    Http::swap(new Factory);
    Http::fake(['*' => Http::response('', 502)]);
    $sync = directory()->refresh($user, true);

    expect($sync->status)->toBe('error')
        ->and($sync->stale_since)->not->toBeNull()
        ->and($user->sshKeys()->fromGithub()->count())->toBe(1)
        ->and(directory()->isStale($user))->toBeTrue();
});

it('drops stale keys after seven days of errors', function () {
    $user = githubUser();
    fakeGitHub(ed25519Line());
    directory()->refresh($user);
    Http::swap(new Factory);
    Http::fake(['*' => Http::response('', 500)]);

    directory()->refresh($user, true);
    $this->travel(7)->days();
    directory()->refresh($user, true);

    expect($user->sshKeys()->fromGithub()->count())->toBe(0);
});

it('clears the stale flag after a good refresh', function () {
    $user = githubUser();
    GithubKeySync::factory()->forUser($user)->errored()->create();
    fakeGitHub(ed25519Line());

    directory()->refresh($user, true);

    expect(directory()->isStale($user))->toBeFalse();
});

it('skips a key that another user already owns', function () {
    $user = githubUser();
    $line = ed25519Line();
    $other = SshKey::factory()->create(['public_key' => $line, 'fingerprint' => 'SHA256:' . rtrim(base64_encode(hash('sha256', base64_decode(explode(' ', $line)[1]), true)), '=')]);
    fakeGitHub($line . "\n" . ed25519Line());

    directory()->refresh($user);

    expect($user->sshKeys()->count())->toBe(1)
        ->and($other->fresh()->user_id)->not->toBe($user->id);
});

it('never builds a url from a login that fails validation', function () {
    $user = githubUser();
    Http::fake(['api.github.com/user/*' => Http::response(['login' => '../evil?x=1'])]);

    $sync = directory()->refresh($user);

    expect($sync->status)->toBe('unknown_account');
    Http::assertSentCount(1);
});

it('does nothing for a user without a linked GitHub account', function () {
    Http::fake();
    $user = User::factory()->create(['github_id' => null]);

    expect(directory()->refresh($user)->status)->toBe('unknown_account');
    Http::assertNothingSent();
    expect(GithubKeySync::count())->toBe(0);
});

it('removes a key that disappeared from GitHub', function () {
    $user = githubUser();
    $kept = ed25519Line();
    fakeGitHub($kept . "\n" . ed25519Line());
    directory()->refresh($user);
    expect($user->sshKeys()->count())->toBe(2);

    fakeGitHub($kept);
    directory()->refresh($user, true);

    expect($user->sshKeys()->count())->toBe(1);
});

it('removes cached keys when the account has none left', function () {
    $user = githubUser();
    fakeGitHub(ed25519Line());
    directory()->refresh($user);

    fakeGitHub('');
    directory()->refresh($user, true);

    expect($user->sshKeys()->count())->toBe(0);
});

it('deletes cached github keys and the sync row when the user has no github id', function () {
    $user = User::factory()->create(['github_id' => null]);
    SshKey::factory()->count(2)->create(['user_id' => $user->id, 'source' => 'github']);
    $manual = SshKey::factory()->create(['user_id' => $user->id, 'source' => 'uploaded']);
    GithubKeySync::query()->create(['user_id' => $user->id, 'github_id' => 1, 'status' => 'ok']);

    $sync = directory()->refresh($user);

    expect($sync->status)->toBe('unknown_account')
        ->and(SshKey::query()->where('user_id', $user->id)->fromGithub()->count())->toBe(0)
        ->and(GithubKeySync::query()->find($user->id))->toBeNull()
        ->and(SshKey::query()->whereKey($manual->id)->exists())->toBeTrue();
});

it('drops the previous identity keys when the sync github id differs, even if GitHub errors', function () {
    $user = githubUser();
    SshKey::factory()->count(2)->create(['user_id' => $user->id, 'source' => 'github']);
    GithubKeySync::query()->create(['user_id' => $user->id, 'github_id' => 1, 'status' => 'ok', 'checked_at' => now()]);
    Http::swap(new Factory);
    Http::fake(['*' => Http::response('', 500)]);

    directory()->refresh($user);

    expect(SshKey::query()->where('user_id', $user->id)->fromGithub()->count())->toBe(0);
});
