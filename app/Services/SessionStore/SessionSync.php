<?php

namespace App\Services\SessionStore;

use App\Models\SessionFile;
use App\Models\Thread;
use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Copies a thread's session files between its sandbox and the session store.
 */
class SessionSync
{
    private const TAIL_BYTES = 256;

    private const QUIET_ATTEMPTS = 20;

    public function __construct(
        private readonly IncusSandboxManager $sandboxes,
        private readonly SessionStore $store,
    ) {}

    /**
     * Uploads the bytes the store has not seen yet for every session file in
     * the sandbox, then checkpoints each append-only file. Waits first until
     * the files stop growing.
     */
    public function syncFromSandbox(Thread $thread, string $container): void
    {
        $directory = $this->projectDirectory($thread);
        $sizes = $this->withinLimits($thread, $this->waitUntilQuiet($container, $directory));

        foreach ($sizes as $relPath => $size) {
            $sessionId = $this->sessionIdOf($relPath);

            if (! $this->store->isValidPath($sessionId, $relPath)) {
                continue;
            }

            $mode = $this->store->modeFor($relPath);

            match ($mode) {
                'append' => $this->uploadAppended($thread, $container, $directory, $sessionId, $relPath, $size),
                'whole' => $this->store->putWhole($thread->id, $sessionId, $relPath, $this->readFrom($container, "{$directory}/{$relPath}", 0, $size)),
                'immutable' => $this->uploadImmutable($thread, $container, $directory, $sessionId, $relPath, $size),
                default => null,
            };
        }

        foreach ($sizes as $relPath => $size) {
            if ($this->store->isValidPath($this->sessionIdOf($relPath), $relPath) && $this->store->modeFor($relPath) === 'append') {
                $this->checkpoint($thread, $container, $directory, $this->sessionIdOf($relPath), $relPath);
            }
        }
    }

    /**
     * Writes every stored session file of the thread into the sandbox's
     * claude config dir, owned by the sandbox user.
     */
    public function restoreIntoSandbox(Thread $thread, string $container): void
    {
        $files = SessionFile::query()->where('thread_id', $thread->id)->get();

        if ($files->isEmpty()) {
            return;
        }

        $directory = $this->projectDirectory($thread);

        foreach ($files as $file) {
            $path = "{$directory}/{$file->rel_path}";
            $result = $this->sandboxes->run(
                $container,
                'mkdir -p ' . escapeshellarg(dirname($path)) . ' && cat > ' . escapeshellarg($path),
                timeout: 60,
                asRoot: true,
                input: $this->store->read($thread->id, $file->session_id, $file->rel_path),
            );

            if (! $result->successful()) {
                throw new RuntimeException("Restoring {$file->rel_path} into {$container} failed: " . $result->errorOutput());
            }
        }

        $this->sandboxes->run($container, 'chown -R yak:yak ' . escapeshellarg($thread->claude_config_dir . '/projects'), timeout: 30, asRoot: true);
    }

    /**
     * Imports the host transcript of a pre-thread task once. Returns false
     * when there is none, so the caller's stale-session fallback applies.
     */
    public function seedFromLegacyTranscript(Thread $thread, string $sessionId): bool
    {
        $path = IncusSandboxManager::sessionTranscriptDir() . '/' . $sessionId . '.jsonl';

        if (! $this->store->isValidPath($sessionId, "{$sessionId}.jsonl") || ! is_file($path)) {
            return false;
        }

        if ($this->store->length($thread->id, $sessionId, "{$sessionId}.jsonl") > 0) {
            return true;
        }

        $content = File::get($path);
        $outcome = $this->store->append($thread->id, $sessionId, "{$sessionId}.jsonl", 0, $content, hash('sha256', $content), hash('sha256', ''));

        return $outcome->status === 200;
    }

    /**
     * Drops files that are too large, that would take the thread past its byte budget, or that
     * exceed the file count, using the listed sizes, and notes what was left out on the thread.
     *
     * @param  array<string, int>  $sizes
     * @return array<string, int>
     */
    private function withinLimits(Thread $thread, array $sizes): array
    {
        $maxFile = (int) config('yak.threads.session_file_max_bytes');
        $maxThread = (int) config('yak.threads.session_thread_max_bytes');
        $maxFiles = (int) config('yak.threads.session_max_files');
        $kept = [];
        $skipped = [];
        $stored = SessionFile::query()->where('thread_id', $thread->id)->pluck('length', 'rel_path');
        $total = (int) $stored->sum();
        $count = $stored->count();

        foreach ($sizes as $relPath => $size) {
            if (! $this->store->isValidPath($this->sessionIdOf($relPath), $relPath)) {
                continue;
            }

            $isNew = ! $stored->has($relPath);
            $added = $isNew ? $size : max(0, $size - (int) $stored->get($relPath));

            if (($isNew && $count >= $maxFiles) || $size > $maxFile || $total + $added > $maxThread) {
                $skipped[] = $relPath;

                continue;
            }

            $kept[$relPath] = $size;
            $total += $added;
            $count += $isNew ? 1 : 0;
        }

        if ($skipped !== []) {
            Log::channel('yak')->warning('Session files over the store limits were not synced', ['thread_id' => $thread->id, 'rel_paths' => array_slice($skipped, 0, 20), 'count' => count($skipped)]);
        }

        $warning = $skipped === [] ? null : mb_strcut(count($skipped) . ' session file(s) over the store limits were not synced: ' . implode(', ', $skipped), 0, 500);

        if ($thread->session_sync_warning !== $warning) {
            $thread->forceFill(['session_sync_warning' => $warning])->saveQuietly();
        }

        return $kept;
    }

    /**
     * @return array<string, int> size by path relative to the project directory
     */
    private function waitUntilQuiet(string $container, string $directory): array
    {
        $sizes = $this->listSizes($container, $directory);

        for ($attempt = 0; $attempt < self::QUIET_ATTEMPTS; $attempt++) {
            Sleep::for((int) config('yak.threads.sync_quiet_ms'))->milliseconds();
            $next = $this->listSizes($container, $directory);

            if ($next === $sizes) {
                break;
            }

            $sizes = $next;
        }

        return $sizes;
    }

    /**
     * @return array<string, int>
     */
    private function listSizes(string $container, string $directory): array
    {
        $result = $this->sandboxes->run($container, 'find ' . escapeshellarg($directory) . " -type f -printf '%s %P\\n' 2>/dev/null", timeout: 30);
        $sizes = [];

        foreach (explode("\n", trim($result->output())) as $line) {
            if (preg_match('/^(\d+) (.+)$/', $line, $matches) === 1) {
                $sizes[$matches[2]] = (int) $matches[1];
            }
        }

        ksort($sizes);

        return $sizes;
    }

    private function uploadAppended(Thread $thread, string $container, string $directory, string $sessionId, string $relPath, int $size): void
    {
        $path = "{$directory}/{$relPath}";
        $chunkBytes = (int) config('yak.threads.session_chunk_bytes');
        $conflicts = 0;

        while (true) {
            $stored = $this->store->length($thread->id, $sessionId, $relPath);

            if ($size <= $stored) {
                return;
            }

            $tailStart = max(0, $stored - self::TAIL_BYTES);
            $tailLength = $stored - $tailStart;
            $bytes = $this->readFrom($container, $path, $tailStart, $tailLength + $chunkBytes);

            if (strlen($bytes) <= $tailLength) {
                return;
            }

            $tail = substr($bytes, 0, $tailLength);
            $chunk = substr($bytes, $tailLength);

            $outcome = $this->store->append($thread->id, $sessionId, $relPath, $stored, $chunk, hash('sha256', $chunk), hash('sha256', $tail));

            if ($outcome->status === 200 && $outcome->length > $stored) {
                continue;
            }

            if ($outcome->status === 200) {
                // No complete line in the chunk: read a bigger one, up to the file limit.
                if ($chunkBytes >= (int) config('yak.threads.session_file_max_bytes')) {
                    return;
                }
                $chunkBytes *= 2;

                continue;
            }

            if (++$conflicts >= 2) {
                break;
            }
        }

        Log::channel('yak')->warning('Session file upload kept conflicting with the store', ['thread_id' => $thread->id, 'rel_path' => $relPath]);
    }

    private function uploadImmutable(Thread $thread, string $container, string $directory, string $sessionId, string $relPath, int $size): void
    {
        $known = SessionFile::query()
            ->where(['thread_id' => $thread->id, 'session_id' => $sessionId, 'rel_path' => $relPath])
            ->whereHas('segments')
            ->exists();

        if (! $known) {
            $this->store->putImmutable($thread->id, $sessionId, $relPath, $this->readFrom($container, "{$directory}/{$relPath}", 0, $size));
        }
    }

    private function checkpoint(Thread $thread, string $container, string $directory, string $sessionId, string $relPath): void
    {
        $result = $this->sandboxes->run($container, 'sha256sum ' . escapeshellarg("{$directory}/{$relPath}"), timeout: 60);
        $hash = strtok(trim($result->output()), ' ');

        if ($result->successful() && $hash !== false) {
            $this->store->checkpoint($thread->id, $sessionId, $relPath, $hash);
        }
    }

    /**
     * Reads a sandbox file from a zero-based byte offset, at most $length bytes (to its end when null).
     */
    private function readFrom(string $container, string $path, int $offset, ?int $length = null): string
    {
        $command = 'tail -c +' . ($offset + 1) . ' ' . escapeshellarg($path) . ($length === null ? '' : ' | head -c ' . $length);
        $result = $this->sandboxes->run($container, $command, timeout: 60);

        if (! $result->successful()) {
            throw new RuntimeException("Reading {$path} from {$container} failed: " . $result->errorOutput());
        }

        return $result->output();
    }

    private function projectDirectory(Thread $thread): string
    {
        return $thread->claude_config_dir . '/projects/' . preg_replace('/[^a-zA-Z0-9]/', '-', IncusSandboxManager::workspacePath());
    }

    private function sessionIdOf(string $relPath): string
    {
        return explode('/', preg_replace('/\.jsonl$/', '', $relPath) ?? $relPath)[0];
    }
}
