<?php

use App\Models\SshKey;
use App\Models\User;
use App\Services\Ssh\InvalidSshKey;
use App\Services\Ssh\SshKeyDirectory;

function pasteableKey(): string
{
    return 'ssh-ed25519 ' . base64_encode(pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . random_bytes(32)) . ' me@laptop';
}

it('adds an uploaded key normalised without its comment', function () {
    $user = User::factory()->create();
    $key = app(SshKeyDirectory::class)->addUploaded($user, 'Laptop', pasteableKey());

    expect($key->public_key)->not->toContain('me@laptop')
        ->and($key->type)->toBe('ssh-ed25519')
        ->and($key->fingerprint)->toStartWith('SHA256:');
});

it('refuses a key already added by another user', function () {
    $paste = pasteableKey();
    app(SshKeyDirectory::class)->addUploaded(User::factory()->create(), 'First', $paste);

    app(SshKeyDirectory::class)->addUploaded(User::factory()->create(), 'Second', $paste);
})->throws(InvalidSshKey::class, 'already added');

it('refuses a key the same user already has', function () {
    $user = User::factory()->create();
    $paste = pasteableKey();
    app(SshKeyDirectory::class)->addUploaded($user, 'First', $paste);

    app(SshKeyDirectory::class)->addUploaded($user, 'Again', $paste);
})->throws(InvalidSshKey::class);

it('does not remove a github key or another user key', function () {
    $user = User::factory()->create();
    $github = SshKey::factory()->forUser($user)->fromGithub()->create();
    $foreign = SshKey::factory()->create();
    $own = SshKey::factory()->forUser($user)->create();
    $directory = app(SshKeyDirectory::class);

    expect($directory->remove($user, $github))->toBeFalse()
        ->and($directory->remove($user, $foreign))->toBeFalse()
        ->and($directory->remove($user, $own))->toBeTrue()
        ->and(SshKey::count())->toBe(2);
});

it('merges uploaded and github keys', function () {
    $user = User::factory()->create();
    SshKey::factory()->forUser($user)->create();
    SshKey::factory()->forUser($user)->fromGithub()->create();
    SshKey::factory()->create();
    $directory = app(SshKeyDirectory::class);

    expect($directory->keysFor($user))->toHaveCount(2)
        ->and($directory->hasKeys($user))->toBeTrue()
        ->and($directory->hasKeys(User::factory()->create()))->toBeFalse();
});

it('finds the owner by exact fingerprint only', function () {
    $key = SshKey::factory()->create();
    $directory = app(SshKeyDirectory::class);

    expect($directory->userForFingerprint($key->fingerprint)?->id)->toBe($key->user_id)
        ->and($directory->userForFingerprint(substr($key->fingerprint, 0, -1)))->toBeNull()
        ->and($directory->userForFingerprint(strtolower($key->fingerprint)))->toBeNull();
});
