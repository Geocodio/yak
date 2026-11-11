<?php

namespace App\Services\Ssh;

use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Keeps the sandbox's ~/.ssh/authorized_keys equal to the drivers' keys plus the short-lived terminal keys. The file
 * is the only way into the sandbox, so it never holds a key of someone who cannot drive the thread.
 */
class SandboxAuthorizedKeys
{
    private const INDEX_KEY = 'ssh:ephemeral:threads';

    private const REMEMBER_SECONDS = 86400;

    public function __construct(
        private SandboxManager $sandboxes,
        private SshAccess $access,
        private SshKeyDirectory $directory,
    ) {}

    public function sync(Thread $thread): void
    {
        $lines = [];

        foreach ($this->access->drivers($thread) as $driver) {
            foreach ($this->directory->keysFor($driver) as $key) {
                $lines[] = 'restrict,pty ' . $key->line();
            }
        }

        foreach ($this->liveEphemeral($thread) as $line) {
            $lines[] = 'restrict,pty ' . $line;
        }

        $result = $this->sandboxes->exec(
            $thread,
            ['sh', '-c', 'umask 077 && mkdir -p ~/.ssh && cat > ~/.ssh/authorized_keys.new && mv ~/.ssh/authorized_keys.new ~/.ssh/authorized_keys'],
            implode("\n", array_unique($lines)) . "\n",
        );

        if ($result->failed()) {
            throw new RuntimeException('Writing authorized_keys failed: ' . trim($result->errorOutput()));
        }
    }

    public function addEphemeral(Thread $thread, SshPublicKey $key, int $ttlSeconds): void
    {
        $entries = $this->entries($thread);
        $entries[$key->fingerprint] = ['line' => $key->line(), 'expires_at' => now()->getTimestamp() + $ttlSeconds];

        $this->remember($thread, $entries);
        $this->sync($thread);
    }

    public function removeEphemeral(Thread $thread, string $fingerprint): void
    {
        $entries = $this->entries($thread);
        unset($entries[$fingerprint]);

        $this->remember($thread, $entries);
        $this->sync($thread);
    }

    /**
     * Drops expired terminal keys and rewrites the file of every running sandbox that held one.
     */
    public function sweep(): void
    {
        foreach (array_keys(Cache::get(self::INDEX_KEY, [])) as $threadId) {
            $thread = Thread::withoutGlobalScopes()->find($threadId);
            $entries = $thread === null ? [] : $this->entries($thread);
            $live = array_filter($entries, fn (array $entry): bool => $entry['expires_at'] > now()->getTimestamp());

            if ($thread === null) {
                $this->forget((int) $threadId);

                continue;
            }

            if (count($live) === count($entries)) {
                continue;
            }

            $this->remember($thread, $live);

            if ($thread->sandbox_state === ThreadSandboxState::Running) {
                $this->sync($thread);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function liveEphemeral(Thread $thread): array
    {
        return array_values(array_map(
            fn (array $entry): string => $entry['line'],
            array_filter($this->entries($thread), fn (array $entry): bool => $entry['expires_at'] > now()->getTimestamp()),
        ));
    }

    /**
     * @return array<string, array{line: string, expires_at: int}>
     */
    private function entries(Thread $thread): array
    {
        return Cache::get("ssh:ephemeral:{$thread->id}", []);
    }

    /**
     * @param  array<string, array{line: string, expires_at: int}>  $entries
     */
    private function remember(Thread $thread, array $entries): void
    {
        $index = Cache::get(self::INDEX_KEY, []);

        if ($entries === []) {
            Cache::forget("ssh:ephemeral:{$thread->id}");
            unset($index[$thread->id]);
        } else {
            Cache::put("ssh:ephemeral:{$thread->id}", $entries, self::REMEMBER_SECONDS);
            $index[$thread->id] = true;
        }

        Cache::put(self::INDEX_KEY, $index, self::REMEMBER_SECONDS * 30);
    }

    private function forget(int $threadId): void
    {
        $index = Cache::get(self::INDEX_KEY, []);
        unset($index[$threadId]);

        Cache::forget("ssh:ephemeral:{$threadId}");
        Cache::put(self::INDEX_KEY, $index, self::REMEMBER_SECONDS * 30);
    }
}
