<?php

namespace App\Services\Threads;

use App\Enums\ClaudeAccountKind;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Placeholders for the auth proxy. A sandbox only ever holds a placeholder
 * (`yakph-` plus 32 random bytes); the proxy asks the Yak app for the real
 * credential behind it over the credential socket and keeps it in memory.
 *
 * A thread or one-shot placeholder names the account and the sandbox it is
 * bound to, and is revoked when its runner ends. A validation placeholder
 * carries the pasted secret itself, encrypted, for a few minutes, and is bound
 * to the host that validates the account. No real credential is written to
 * Redis or to a sandbox.
 */
class AuthProxyPlaceholders
{
    public const PREFIX = 'yakph-';

    public const VALIDATION_TTL_SECONDS = 300;

    /** Longer than any runner lives: an idle sandbox stops after minutes and a turn is capped. */
    private const BOUND_TTL_SECONDS = 172_800;

    public function issueForThread(Thread $thread, ClaudeAccount $account): string
    {
        $this->revokeForThread($thread);

        $placeholder = $this->newPlaceholder();
        $hash = $this->hash($placeholder);

        $this->store()->put($this->key($hash), ['account_id' => $account->id, 'thread_id' => $thread->id], self::BOUND_TTL_SECONDS);
        $this->store()->put($this->threadKey($thread->id), $hash, self::BOUND_TTL_SECONDS);

        return $placeholder;
    }

    /**
     * For a run that belongs to no thread: bound to the job's own container.
     */
    public function issueForContainer(string $container, ClaudeAccount $account): string
    {
        $placeholder = $this->newPlaceholder();

        $this->store()->put($this->key($this->hash($placeholder)), ['account_id' => $account->id, 'container' => $container], self::BOUND_TTL_SECONDS);

        return $placeholder;
    }

    public function issueForValidation(ClaudeAccountKind $kind, string $secret): string
    {
        $placeholder = $this->newPlaceholder();
        $credential = ['type' => $kind === ClaudeAccountKind::ApiKey ? 'api_key' : 'oauth_token', 'value' => $secret];

        $this->store()->put($this->key($this->hash($placeholder)), ['encrypted' => Crypt::encryptString(json_encode($credential, JSON_THROW_ON_ERROR))], self::VALIDATION_TTL_SECONDS);

        return $placeholder;
    }

    public function revoke(string $placeholder): void
    {
        $this->store()->forget($this->key($this->hash($placeholder)));
    }

    public function revokeForThread(Thread $thread): void
    {
        $hash = $this->store()->pull($this->threadKey($thread->id));

        if (is_string($hash)) {
            $this->store()->forget($this->key($hash));
        }
    }

    /**
     * What the proxy needs to serve a placeholder: the real credential and
     * where the placeholder may be used from. Null when the placeholder is
     * unknown, revoked or its account or sandbox is gone.
     *
     * @return array{type: string, value: string, container?: string, host?: true}|null
     */
    public function resolve(string $placeholder): ?array
    {
        if (! str_starts_with($placeholder, self::PREFIX)) {
            return null;
        }

        $binding = $this->store()->get($this->key($this->hash($placeholder)));

        if (! is_array($binding)) {
            return null;
        }

        if (isset($binding['encrypted'])) {
            /** @var array{type: string, value: string} $credential */
            $credential = json_decode(Crypt::decryptString($binding['encrypted']), true, flags: JSON_THROW_ON_ERROR);

            return $credential + ['host' => true];
        }

        $account = ClaudeAccount::query()->find((int) $binding['account_id']);
        $container = $this->containerOf($binding);

        if ($account === null || $container === null) {
            return null;
        }

        return [
            'type' => $account->kind === ClaudeAccountKind::ApiKey ? 'api_key' : 'oauth_token',
            'value' => $account->secret,
            'container' => $container,
        ];
    }

    /**
     * @param  array<string, mixed>  $binding
     */
    private function containerOf(array $binding): ?string
    {
        if (isset($binding['container'])) {
            return (string) $binding['container'];
        }

        $thread = Thread::query()->find((int) ($binding['thread_id'] ?? 0));

        return $thread?->isOpen() === true && $thread->sandbox_container !== null ? $thread->sandbox_container : null;
    }

    private function newPlaceholder(): string
    {
        return self::PREFIX . bin2hex(random_bytes(32));
    }

    private function hash(string $placeholder): string
    {
        return hash('sha256', $placeholder);
    }

    private function key(string $hash): string
    {
        return 'auth-proxy-placeholder:' . $hash;
    }

    private function threadKey(int $threadId): string
    {
        return 'auth-proxy-thread:' . $threadId;
    }

    private function store(): Repository
    {
        /** @var Repository */
        return Cache::store((string) config('yak.credential_reference_store'));
    }
}
