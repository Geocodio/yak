<?php

namespace App\Services\SessionStore;

use App\Models\SessionFile;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
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

    private const QUIET_ROUNDS = 20;

    private const QUIET_MILLISECONDS = 500;

    private const CHUNK_BYTES = 1048576;

    private const MAX_CHUNK_BYTES = 67108864;

    private const WORKSPACE_PATH = '/workspace';

    public function __construct(
        private readonly SandboxManager $sandboxes,
        private readonly SessionStore $store,
    ) {}

    /**
     * Uploads the bytes the store has not seen yet for every session file in
     * the sandbox, then checkpoints each append-only file. Waits first until
     * the files stop growing.
     */
    public function syncFromSandbox(Thread $thread): void
    {
        $directory = $this->projectDirectory($thread);
        $sizes = $this->waitUntilQuiet($thread, $directory);
        $appended = [];

        foreach ($sizes as $relativePath => $size) {
            $sessionId = $this->sessionIdOf($relativePath);

            if (! $this->store->isValidPath($sessionId, $relativePath)) {
                continue;
            }

            switch ($this->store->modeFor($relativePath)) {
                case 'append':
                    $this->uploadAppended($thread, $directory, $sessionId, $relativePath, $size);
                    $appended[$relativePath] = $sessionId;
                    break;
                case 'whole':
                    $this->store->putWhole($thread, $sessionId, $relativePath, $this->readFrom($thread, "{$directory}/{$relativePath}", 0, $size));
                    break;
                case 'immutable':
                    $this->uploadImmutable($thread, $directory, $sessionId, $relativePath, $size);
                    break;
            }
        }

        foreach ($appended as $relativePath => $sessionId) {
            $this->checkpoint($thread, $directory, $sessionId, $relativePath);
        }
    }

    /**
     * Writes every stored session file of the thread into the sandbox's
     * claude config dir, owned by the sandbox user.
     */
    public function restoreIntoSandbox(Thread $thread): void
    {
        $files = SessionFile::query()->where('thread_id', $thread->id)->get();

        if ($files->isEmpty()) {
            return;
        }

        $directory = $this->projectDirectory($thread);

        foreach ($files as $file) {
            $path = "{$directory}/{$file->rel_path}";
            $result = $this->sandboxes->exec(
                $thread,
                ['bash', '-c', 'mkdir -p ' . escapeshellarg(dirname($path)) . ' && cat > ' . escapeshellarg($path)],
                stdin: $this->store->read($thread, $file->session_id, $file->rel_path),
            );

            if (! $result->successful()) {
                throw new RuntimeException("Restoring {$file->rel_path} into the sandbox of thread {$thread->id} failed: " . $result->errorOutput());
            }
        }

        $this->sandboxes->exec($thread, ['bash', '-c', 'chown -R yak:yak ' . escapeshellarg($thread->claude_config_dir . '/projects')]);
    }

    /**
     * Imports the host transcript of a pre-thread task once. Returns false
     * when there is none, so the caller's stale-session fallback applies.
     */
    public function seedFromLegacyTranscript(Thread $thread, string $sessionId): bool
    {
        $relativePath = "{$sessionId}.jsonl";
        $path = rtrim((string) config('yak.sandbox.session_transcript_path'), '/') . '/' . $relativePath;

        if (! $this->store->isValidPath($sessionId, $relativePath) || ! is_file($path)) {
            return false;
        }

        if ($this->store->length($thread, $sessionId, $relativePath) > 0) {
            return true;
        }

        $content = File::get($path);

        return $this->store->append($thread, $sessionId, $relativePath, 0, $content, hash('sha256', $content), hash('sha256', ''))->status === 200;
    }

    /**
     * @return array<string, int> size by path relative to the project directory
     */
    private function waitUntilQuiet(Thread $thread, string $directory): array
    {
        $sizes = $this->listSizes($thread, $directory);

        for ($round = 0; $round < self::QUIET_ROUNDS; $round++) {
            Sleep::for(self::QUIET_MILLISECONDS)->milliseconds();
            $next = $this->listSizes($thread, $directory);

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
    private function listSizes(Thread $thread, string $directory): array
    {
        $result = $this->sandboxes->exec($thread, ['bash', '-lc', 'find ' . escapeshellarg($directory) . " -type f -printf '%s %P\\n' 2>/dev/null"]);
        $sizes = [];

        foreach (explode("\n", trim($result->output())) as $line) {
            if (preg_match('/^(\d+) (.+)$/', $line, $matches) === 1) {
                $sizes[$matches[2]] = (int) $matches[1];
            }
        }

        ksort($sizes);

        return $sizes;
    }

    private function uploadAppended(Thread $thread, string $directory, string $sessionId, string $relativePath, int $size): void
    {
        $path = "{$directory}/{$relativePath}";
        $chunkBytes = self::CHUNK_BYTES;
        $conflicts = 0;

        while (true) {
            $stored = $this->store->length($thread, $sessionId, $relativePath);

            if ($size <= $stored) {
                return;
            }

            $tailStart = max(0, $stored - self::TAIL_BYTES);
            $tailLength = $stored - $tailStart;
            $bytes = $this->readFrom($thread, $path, $tailStart, $tailLength + $chunkBytes);

            if (strlen($bytes) <= $tailLength) {
                return;
            }

            $tail = substr($bytes, 0, $tailLength);
            $chunk = substr($bytes, $tailLength);
            $outcome = $this->store->append($thread, $sessionId, $relativePath, $stored, $chunk, hash('sha256', $chunk), hash('sha256', $tail));

            if ($outcome->status === 200 && $outcome->length > $stored) {
                continue;
            }

            if ($outcome->status === 200) {
                // No complete line in the chunk: a half-written last line waits for the next
                // sync, an oversized line gets a bigger read up to the cap.
                if (strlen($chunk) < $chunkBytes || $chunkBytes >= self::MAX_CHUNK_BYTES) {
                    return;
                }
                $chunkBytes *= 2;

                continue;
            }

            if (++$conflicts >= 2) {
                break;
            }
        }

        Log::channel('yak')->warning('Session file upload kept conflicting with the store', ['thread_id' => $thread->id, 'rel_path' => $relativePath]);
    }

    private function uploadImmutable(Thread $thread, string $directory, string $sessionId, string $relativePath, int $size): void
    {
        $known = SessionFile::query()
            ->where(['thread_id' => $thread->id, 'session_id' => $sessionId, 'rel_path' => $relativePath])
            ->whereHas('segments')
            ->exists();

        if (! $known) {
            $this->store->putImmutable($thread, $sessionId, $relativePath, $this->readFrom($thread, "{$directory}/{$relativePath}", 0, $size));
        }
    }

    private function checkpoint(Thread $thread, string $directory, string $sessionId, string $relativePath): void
    {
        $result = $this->sandboxes->exec($thread, ['bash', '-c', 'sha256sum ' . escapeshellarg("{$directory}/{$relativePath}")]);
        $hash = strtok(trim($result->output()), ' ');

        if ($result->successful() && $hash !== false) {
            $this->store->checkpoint($thread, $sessionId, $relativePath, $hash);
        }
    }

    /**
     * Reads a sandbox file from a zero-based byte offset, at most $length bytes.
     */
    private function readFrom(Thread $thread, string $path, int $offset, int $length): string
    {
        $command = 'tail -c +' . ($offset + 1) . ' ' . escapeshellarg($path) . ' | head -c ' . $length;
        $result = $this->sandboxes->exec($thread, ['bash', '-c', $command]);

        if (! $result->successful()) {
            throw new RuntimeException("Reading {$path} from the sandbox of thread {$thread->id} failed: " . $result->errorOutput());
        }

        return $result->output();
    }

    private function projectDirectory(Thread $thread): string
    {
        return $thread->claude_config_dir . '/projects/' . preg_replace('/[^a-zA-Z0-9]/', '-', self::WORKSPACE_PATH);
    }

    private function sessionIdOf(string $relativePath): string
    {
        if (str_starts_with($relativePath, 'memory/')) {
            return SessionStore::MEMORY_SESSION_ID;
        }

        return explode('/', preg_replace('/\.jsonl$/', '', $relativePath) ?? $relativePath)[0];
    }
}
