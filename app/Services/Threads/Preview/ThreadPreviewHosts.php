<?php

namespace App\Services\Threads\Preview;

use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Models\BranchDeployment;
use App\Models\Thread;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Single-label preview hosts for threads: t-<random10>.<hostname suffix>.
 */
class ThreadPreviewHosts
{
    private const PREFIX = 't-';

    public function hostnameFor(Thread $thread): string
    {
        if ($thread->preview_hostname !== null) {
            return $thread->preview_hostname;
        }

        do {
            $candidate = self::PREFIX . Str::lower(Str::random(10)) . '.' . $this->suffix();
            if (BranchDeployment::withoutGlobalScopes()->where('hostname', $candidate)->exists()) {
                continue;
            }

            try {
                $updated = Thread::withoutGlobalScopes()
                    ->whereKey($thread->getKey())
                    ->whereNull('preview_hostname')
                    ->update(['preview_hostname' => $candidate]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            $thread->refresh();
            if ($updated === 0 && $thread->preview_hostname === null) {
                continue;
            }

            return (string) $thread->preview_hostname;
        } while (true);
    }

    public function threadForHost(string $host): ?Thread
    {
        $host = strtolower($host);
        if (! $this->isThreadHost($host)) {
            return null;
        }

        return Thread::withoutGlobalScopes()->where('preview_hostname', $host)->first();
    }

    public function isThreadHost(string $host): bool
    {
        $host = strtolower($host);

        return str_starts_with($host, self::PREFIX) && str_ends_with($host, '.' . $this->suffix());
    }

    public function urlFor(Thread $thread, string $path = '/'): string
    {
        return 'https://' . $this->hostnameFor($thread) . '/' . ltrim($path, '/');
    }

    /**
     * The "Preview" section of the agent's environment note, or null when the
     * thread has no preview (local machine owner, or no usable manifest).
     */
    public function environmentSection(Thread $thread): ?string
    {
        if ($thread->owner_side === ThreadOwnerSide::Device || $thread->sandbox_state !== ThreadSandboxState::Running) {
            return null;
        }

        $result = app(ThreadPreviewManifestReader::class)->read($thread);

        if ($result->manifest === null) {
            return null;
        }

        return "The dev server for this thread is served at {$this->urlFor($thread)}. You may restart it; the health probe is {$result->manifest->healthProbePath}.";
    }

    private function suffix(): string
    {
        return strtolower((string) config('yak.deployments.hostname_suffix'));
    }
}
