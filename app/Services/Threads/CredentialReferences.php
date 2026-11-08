<?php

namespace App\Services\Threads;

use App\Enums\ClaudeAccountKind;
use App\Models\ClaudeAccount;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * One-time references to a runner credential. The credential itself never goes
 * through Redis: the thread driver stores it here, encrypted, under a random
 * reference that expires after a minute, and the broker redeems the reference
 * over the credential socket. A redeemed reference is gone.
 */
class CredentialReferences
{
    public const TIME_TO_LIVE_SECONDS = 60;

    public function issue(ClaudeAccount $account): string
    {
        $reference = Str::random(64);
        $credential = [
            'type' => $account->kind === ClaudeAccountKind::ApiKey ? 'api_key' : 'oauth_token',
            'value' => $account->secret,
        ];

        $this->store()->put($this->key($reference), Crypt::encryptString(json_encode($credential, JSON_THROW_ON_ERROR)), self::TIME_TO_LIVE_SECONDS);

        return $reference;
    }

    /**
     * @return array{type: string, value: string}|null
     */
    public function redeem(string $reference): ?array
    {
        $key = $this->key($reference);

        // The lock makes get-then-forget atomic, so two redemptions cannot both win.
        $provider = $this->store()->getStore();
        $encrypted = $provider instanceof LockProvider
            ? $provider->lock('lock:' . $key, 5)->block(2, fn (): mixed => $this->store()->pull($key))
            : $this->store()->pull($key);

        if (! is_string($encrypted)) {
            return null;
        }

        /** @var array{type: string, value: string} */
        return json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
    }

    private function key(string $reference): string
    {
        return 'credential-reference:' . hash('sha256', $reference);
    }

    private function store(): Repository
    {
        /** @var Repository */
        return Cache::store((string) config('yak.credential_reference_store'));
    }
}
