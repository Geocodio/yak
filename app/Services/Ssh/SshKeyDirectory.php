<?php

namespace App\Services\Ssh;

use App\Enums\SshKeySource;
use App\Models\SshKey;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Who owns which public key. A fingerprint belongs to exactly one user, so a key can never grant a second person access.
 */
class SshKeyDirectory
{
    public function userForFingerprint(string $fingerprint): ?User
    {
        $key = SshKey::query()->where('fingerprint', $fingerprint)->first();

        // Database collations may fold case; a base64 fingerprint must match exactly.
        return $key !== null && hash_equals($key->fingerprint, $fingerprint) ? $key->user : null;
    }

    /**
     * Uploaded keys plus the cached GitHub keys.
     *
     * @return list<SshPublicKey>
     */
    public function keysFor(User $user): array
    {
        return array_values($user->sshKeys()->orderBy('id')->get()
            ->map(fn (SshKey $key): SshPublicKey => SshPublicKey::parse($key->public_key))
            ->all());
    }

    public function hasKeys(User $user): bool
    {
        return $user->sshKeys()->exists();
    }

    /**
     * @throws InvalidSshKey
     */
    public function addUploaded(User $user, string $name, string $publicKey): SshKey
    {
        $parsed = SshPublicKey::parse($publicKey);

        if (SshKey::query()->where('fingerprint', $parsed->fingerprint)->exists()) {
            throw InvalidSshKey::for('duplicate');
        }

        try {
            return SshKey::query()->create([
                'user_id' => $user->id,
                'source' => SshKeySource::Uploaded,
                'name' => $name,
                'type' => $parsed->type,
                'public_key' => $parsed->line(),
                'fingerprint' => $parsed->fingerprint,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw InvalidSshKey::for('duplicate');
        }
    }

    /**
     * Removes an uploaded key. GitHub keys are managed on GitHub, so they stay.
     */
    public function remove(User $user, SshKey $key): bool
    {
        if ($key->user_id !== $user->id || $key->source !== SshKeySource::Uploaded) {
            return false;
        }

        return (bool) $key->delete();
    }
}
