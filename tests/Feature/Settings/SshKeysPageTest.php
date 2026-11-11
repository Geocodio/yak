<?php

use App\Models\GithubKeySync;
use App\Models\SshKey;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function pasteKey(): string
{
    return 'ssh-ed25519 ' . base64_encode(pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . random_bytes(32)) . ' me@computer';
}

beforeEach(function () {
    config()->set('inertia.testing.ensure_pages_exist', false);
    config()->set('app.url', 'https://geocodio.yak.build');
    config()->set('yak.ssh.jump_host', null);
    $this->user = User::factory()->create(['github_id' => 77, 'github_login' => 'dana-whitfield']);
    $this->actingAs($this->user);
});

it('shows keys, the github account and the config snippet', function () {
    SshKey::factory()->forUser($this->user)->fromGithub()->create(['name' => 'MacBook Pro']);
    SshKey::factory()->forUser($this->user)->create(['name' => 'Work computer']);
    SshKey::factory()->create(['name' => 'Someone else']);
    GithubKeySync::factory()->forUser($this->user)->create(['github_login' => 'dana-whitfield']);

    $this->get(route('settings.ssh-keys'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/SshKeys')
            ->has('githubKeys', 1)
            ->where('githubKeys.0.name', 'MacBook Pro')
            ->where('githubKeys.0.source', 'github')
            ->has('uploadedKeys', 1)
            ->where('uploadedKeys.0.name', 'Work computer')
            ->where('github.linked', true)
            ->where('github.login', 'dana-whitfield')
            ->where('github.profileUrl', 'https://github.com/dana-whitfield')
            ->where('github.status', 'ok')
            ->where('github.isStale', false)
            ->where('config.command', 'yak ssh --config')
            ->where('config.jumpHost', 'ssh.geocodio.yak.build:2222')
            ->where('config.snippet', fn (string $snippet) => str_contains($snippet, 'Host *.geocodio.yak.build') && str_contains($snippet, 'ProxyJump yak-jump@ssh.geocodio.yak.build:2222'))
            ->etc());
});

it('shows the empty state without a GitHub link or keys', function () {
    $user = User::factory()->create(['github_id' => null, 'github_login' => null]);

    $this->actingAs($user)->get(route('settings.ssh-keys'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('githubKeys', 0)
            ->has('uploadedKeys', 0)
            ->where('github.linked', false)
            ->where('github.login', null)
            ->where('github.profileUrl', null)
            ->where('github.status', 'unknown_account')
            ->where('github.isStale', false)
            ->etc());
});

it('flags stale github keys', function () {
    GithubKeySync::factory()->forUser($this->user)->errored()->create();

    $this->get(route('settings.ssh-keys'))
        ->assertInertia(fn (Assert $page) => $page->where('github.isStale', true)->where('github.status', 'error')->etc());
});

it('stores an uploaded key', function () {
    $this->post(route('settings.ssh-keys.store'), ['name' => 'Laptop', 'public_key' => pasteKey()])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    expect($this->user->sshKeys()->uploaded()->count())->toBe(1);
});

it('rejects a private key with the inline message and stores nothing', function () {
    $this->post(route('settings.ssh-keys.store'), [
        'name' => 'Oops',
        'public_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----",
    ])->assertSessionHasErrors(['public_key' => 'That looks like a private key. Paste the public key, the one in the .pub file, and keep the private key to yourself.']);

    expect(SshKey::count())->toBe(0);
});

it('rejects a duplicate key', function () {
    $paste = pasteKey();
    $this->post(route('settings.ssh-keys.store'), ['name' => 'A', 'public_key' => $paste]);

    $this->post(route('settings.ssh-keys.store'), ['name' => 'B', 'public_key' => $paste])
        ->assertSessionHasErrors('public_key');

    expect(SshKey::count())->toBe(1);
});

it('requires a name', function () {
    $this->post(route('settings.ssh-keys.store'), ['name' => '', 'public_key' => pasteKey()])
        ->assertSessionHasErrors('name');
});

it('removes an own uploaded key', function () {
    $key = SshKey::factory()->forUser($this->user)->create();

    $this->delete(route('settings.ssh-keys.destroy', $key))->assertRedirect();

    expect(SshKey::count())->toBe(0);
});

it('answers 404 for another user key and keeps it', function () {
    $key = SshKey::factory()->create();

    $this->delete(route('settings.ssh-keys.destroy', $key))->assertNotFound();

    expect($key->fresh())->not->toBeNull();
});

it('answers 404 for a github key', function () {
    $key = SshKey::factory()->forUser($this->user)->fromGithub()->create();

    $this->delete(route('settings.ssh-keys.destroy', $key))->assertNotFound();

    expect($key->fresh())->not->toBeNull();
});

it('forces a GitHub call on refresh', function () {
    Http::swap(new Factory);
    Http::fake([
        'api.github.com/user/77' => Http::response(['login' => 'dana-whitfield']),
        'github.com/dana-whitfield.keys' => Http::response(pasteKey()),
    ]);
    GithubKeySync::factory()->forUser($this->user)->create(['github_login' => 'dana-whitfield']);

    $this->post(route('settings.ssh-keys.refresh'))->assertRedirect();

    Http::assertSentCount(2);
    expect($this->user->sshKeys()->fromGithub()->count())->toBe(1);
});

it('redirects guests', function () {
    auth()->logout();

    $this->get(route('settings.ssh-keys'))->assertRedirect();
    $this->post(route('settings.ssh-keys.refresh'))->assertRedirect();
});
