<?php

namespace App\Services\SessionStore;

use App\DataTransferObjects\AppendOutcome;
use App\Models\SessionFile;
use App\Models\SessionFileSegment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class SessionStore
{
    private const TAIL_BYTES = 256;

    public function append(
        int $threadId,
        string $sessionId,
        string $relPath,
        int $offset,
        string $chunk,
        string $chunkSha256,
        string $tailSha256,
    ): AppendOutcome {
        if (! hash_equals(hash('sha256', $chunk), strtolower($chunkSha256))) {
            throw new InvalidArgumentException('chunk_sha256 does not match the chunk.');
        }
        $this->assertValidPath($sessionId, $relPath);
        if ($this->modeFor($relPath) !== 'append') {
            throw new InvalidArgumentException("{$relPath} is not an append-only file.");
        }

        return DB::transaction(function () use ($threadId, $sessionId, $relPath, $offset, $chunk, $tailSha256): AppendOutcome {
            $file = $this->lockedFile($threadId, $sessionId, $relPath, 'append');
            $length = (int) $file->length;

            if ($offset > $length) {
                return AppendOutcome::rejected('gap', $length);
            }

            $tailStart = max(0, $offset - self::TAIL_BYTES);
            $tail = $this->readRange($file, $tailStart, $offset);
            if (! hash_equals(hash('sha256', $tail), strtolower($tailSha256))) {
                return AppendOutcome::rejected('diverged', $length);
            }

            $overlap = min(strlen($chunk), $length - $offset);
            if ($overlap > 0 && $this->readRange($file, $offset, $offset + $overlap) !== substr($chunk, 0, $overlap)) {
                return AppendOutcome::rejected('diverged', $length);
            }

            $fresh = substr($chunk, $overlap);
            $lastNewline = strrpos($fresh, "\n");
            if ($lastNewline === false) {
                return AppendOutcome::accepted($length, $overlap > 0);
            }

            $bytes = substr($fresh, 0, $lastNewline + 1);
            $this->writeSegment($file, $length, $bytes);
            $this->indexEntries($file, $length, $bytes);
            $file->update(['length' => $length + strlen($bytes)]);

            return AppendOutcome::accepted((int) $file->length, $overlap > 0);
        });
    }

    /**
     * Compares the client's whole-file hash with the stored bytes. A mismatch is
     * logged and left alone: the store never rewrites an append-only file.
     */
    public function checkpoint(int $threadId, string $sessionId, string $relPath, string $wholeSha256): bool
    {
        $this->assertValidPath($sessionId, $relPath);
        $file = SessionFile::query()
            ->where(['thread_id' => $threadId, 'session_id' => $sessionId, 'rel_path' => $relPath])
            ->first();
        $storedSha256 = hash('sha256', $file ? $this->readFile($file) : '');

        if (! $file || ! hash_equals($storedSha256, strtolower($wholeSha256))) {
            Log::warning('Session file checkpoint mismatch', [
                'thread_id' => $threadId,
                'session_id' => $sessionId,
                'rel_path' => $relPath,
                'stored_sha256' => $storedSha256,
                'client_sha256' => $wholeSha256,
            ]);

            return false;
        }

        $file->update(['sha256' => $storedSha256, 'checkpoint_sha256' => $storedSha256]);

        return true;
    }

    public function putWhole(int $threadId, string $sessionId, string $relPath, string $content): void
    {
        $this->assertValidPath($sessionId, $relPath);
        if ($this->modeFor($relPath) !== 'whole') {
            throw new InvalidArgumentException("{$relPath} is not a whole-file path.");
        }

        DB::transaction(function () use ($threadId, $sessionId, $relPath, $content): void {
            $file = $this->lockedFile($threadId, $sessionId, $relPath, 'whole');
            $this->removeSegments($file);
            $this->writeSegment($file, 0, $content);
            $file->update(['length' => strlen($content), 'sha256' => hash('sha256', $content)]);
        });
    }

    /**
     * First write wins. Returns false when the file already exists.
     */
    public function putImmutable(int $threadId, string $sessionId, string $relPath, string $content): bool
    {
        $this->assertValidPath($sessionId, $relPath);
        if ($this->modeFor($relPath) !== 'immutable') {
            throw new InvalidArgumentException("{$relPath} is not an immutable path.");
        }

        return DB::transaction(function () use ($threadId, $sessionId, $relPath, $content): bool {
            $file = $this->lockedFile($threadId, $sessionId, $relPath, 'immutable');
            if ($file->segments()->exists()) {
                return false;
            }
            $this->writeSegment($file, 0, $content);
            $file->update(['length' => strlen($content), 'sha256' => hash('sha256', $content)]);

            return true;
        });
    }

    public function length(int $threadId, string $sessionId, string $relPath): int
    {
        $this->assertValidPath($sessionId, $relPath);

        return (int) SessionFile::query()
            ->where(['thread_id' => $threadId, 'session_id' => $sessionId, 'rel_path' => $relPath])
            ->value('length');
    }

    public function read(int $threadId, string $sessionId, string $relPath): string
    {
        $this->assertValidPath($sessionId, $relPath);
        $file = SessionFile::query()
            ->where(['thread_id' => $threadId, 'session_id' => $sessionId, 'rel_path' => $relPath])
            ->first();

        return $file ? $this->readFile($file) : '';
    }

    /**
     * @return Collection<int, SessionFile>
     */
    public function files(int $threadId, string $sessionId): Collection
    {
        return SessionFile::query()
            ->where(['thread_id' => $threadId, 'session_id' => $sessionId])
            ->orderBy('rel_path')
            ->get();
    }

    public function deleteThread(int $threadId): void
    {
        SessionFile::query()->where('thread_id', $threadId)->delete();
        File::deleteDirectory($this->basePath() . '/' . $threadId);
    }

    /**
     * A session file is <sid>.jsonl, <sid>/subagents/<name>.jsonl|.meta.json or <sid>/tool-results/<path>,
     * where <sid> is a UUID and no segment is "." or "..".
     */
    public function isValidPath(string $sessionId, string $relPath): bool
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $sessionId) !== 1) {
            return false;
        }

        $name = '[A-Za-z0-9_][A-Za-z0-9._-]*';
        $id = preg_quote($sessionId, '/');
        $shapes = [
            "/^{$id}\\.jsonl$/",
            "/^{$id}\\/subagents\\/{$name}\\.(jsonl|meta\\.json)$/",
            "/^{$id}\\/tool-results\\/({$name}\\/)*{$name}$/",
        ];

        foreach ($shapes as $shape) {
            if (preg_match($shape, $relPath) === 1 && ! str_contains($relPath, '..')) {
                return true;
            }
        }

        return false;
    }

    public function modeFor(string $relPath): ?string
    {
        return match (true) {
            str_contains($relPath, '/tool-results/') => 'immutable',
            str_ends_with($relPath, '.meta.json') => 'whole',
            str_ends_with($relPath, '.jsonl') => 'append',
            default => null,
        };
    }

    private function assertValidPath(string $sessionId, string $relPath): void
    {
        if (! $this->isValidPath($sessionId, $relPath)) {
            throw new InvalidArgumentException("{$relPath} is not a valid session file for session {$sessionId}.");
        }
    }

    private function basePath(): string
    {
        return rtrim((string) config('yak.threads.session_store_path'), '/');
    }

    private function lockedFile(int $threadId, string $sessionId, string $relPath, string $mode): SessionFile
    {
        $key = ['thread_id' => $threadId, 'session_id' => $sessionId, 'rel_path' => $relPath];
        SessionFile::query()->firstOrCreate($key, ['mode' => $mode]);

        return SessionFile::query()->where($key)->lockForUpdate()->firstOrFail();
    }

    private function writeSegment(SessionFile $file, int $offset, string $bytes): void
    {
        $directory = $this->basePath() . '/' . $file->thread_id . '/' . $file->id;
        File::ensureDirectoryExists($directory, 0700);
        $path = "{$directory}/{$offset}.seg";
        $temporaryPath = "{$path}.tmp";

        File::put($temporaryPath, Crypt::encryptString(base64_encode($bytes)));
        chmod($temporaryPath, 0600);
        rename($temporaryPath, $path);

        SessionFileSegment::query()->updateOrCreate(
            ['session_file_id' => $file->id, 'offset' => $offset],
            ['length' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'path' => $path],
        );
    }

    private function removeSegments(SessionFile $file): void
    {
        foreach ($file->segments()->get() as $segment) {
            File::delete($segment->path);
        }
        $file->segments()->delete();
    }

    private function readFile(SessionFile $file): string
    {
        return $this->readRange($file, 0, (int) $file->length);
    }

    /**
     * Decrypts only the segments that overlap [start, end).
     */
    private function readRange(SessionFile $file, int $start, int $end): string
    {
        if ($end <= $start) {
            return '';
        }

        $out = '';
        $segments = $file->segments()
            ->where('offset', '<', $end)
            ->get()
            ->filter(fn (SessionFileSegment $segment): bool => $segment->offset + $segment->length > $start);

        foreach ($segments as $segment) {
            $bytes = base64_decode(Crypt::decryptString(File::get($segment->path)), true);
            $from = max(0, $start - $segment->offset);
            $to = min($segment->length, $end - $segment->offset);
            $out .= substr((string) $bytes, $from, $to - $from);
        }

        return $out;
    }

    private function indexEntries(SessionFile $file, int $baseOffset, string $bytes): void
    {
        $cursor = 0;
        foreach (explode("\n", rtrim($bytes, "\n")) as $line) {
            $lineOffset = $baseOffset + $cursor;
            $cursor += strlen($line) + 1;

            $decoded = json_decode($line, true);
            if (! is_array($decoded) || ! is_string($decoded['uuid'] ?? null)) {
                continue;
            }

            $now = now();
            DB::table('session_entries')->insertOrIgnore([
                'session_id' => $file->session_id,
                'uuid' => $decoded['uuid'],
                'parent_uuid' => is_string($decoded['parentUuid'] ?? null) ? $decoded['parentUuid'] : null,
                'session_file_id' => $file->id,
                'offset' => $lineOffset,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
