<?php

namespace App\Services\SessionStore;

use App\DataTransferObjects\AppendOutcome;
use App\Models\SessionFile;
use App\Models\SessionFileSegment;
use App\Models\Thread;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class SessionStore
{
    public const MEMORY_SESSION_ID = '_memory';

    private const TAIL_BYTES = 256;

    private const NAME_PATTERN = '[A-Za-z0-9_][A-Za-z0-9._-]*';

    public function __construct(private SegmentCipher $cipher) {}

    public function append(
        Thread $thread,
        string $sessionId,
        string $relativePath,
        int $offset,
        string $chunk,
        string $chunkSha256,
        string $tailSha256,
    ): AppendOutcome {
        if (! hash_equals(hash('sha256', $chunk), strtolower($chunkSha256))) {
            throw new InvalidArgumentException('chunk_sha256 does not match the chunk.');
        }
        $this->assertValidPath($sessionId, $relativePath);
        if ($this->modeFor($relativePath) !== 'append') {
            throw new InvalidArgumentException("{$relativePath} is not an append-only file.");
        }

        return DB::transaction(function () use ($thread, $sessionId, $relativePath, $offset, $chunk, $tailSha256): AppendOutcome {
            $file = $this->lockedFile($thread, $sessionId, $relativePath, 'append');
            $length = (int) $file->length;

            if ($offset > $length) {
                return AppendOutcome::rejected('gap', $length);
            }

            $tail = $this->readRange($file, max(0, $offset - self::TAIL_BYTES), $offset);
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
     * logged and the file is left alone: the store never rewrites an append-only file.
     */
    public function checkpoint(Thread $thread, string $sessionId, string $relativePath, string $wholeSha256): bool
    {
        $this->assertValidPath($sessionId, $relativePath);
        $file = $this->findFile($thread, $sessionId, $relativePath);
        $storedSha256 = hash('sha256', $file ? $this->readFile($file) : '');

        if (! $file || ! hash_equals($storedSha256, strtolower($wholeSha256))) {
            Log::warning('Session file checkpoint mismatch', [
                'thread_id' => $thread->id,
                'session_id' => $sessionId,
                'rel_path' => $relativePath,
                'stored_sha256' => $storedSha256,
                'client_sha256' => $wholeSha256,
            ]);

            return false;
        }

        $file->update(['sha256' => $storedSha256, 'checkpoint_sha256' => $storedSha256]);

        return true;
    }

    public function putWhole(Thread $thread, string $sessionId, string $relativePath, string $content): void
    {
        $this->assertValidPath($sessionId, $relativePath);
        if ($this->modeFor($relativePath) !== 'whole') {
            throw new InvalidArgumentException("{$relativePath} is not a whole-file path.");
        }

        DB::transaction(function () use ($thread, $sessionId, $relativePath, $content): void {
            $file = $this->lockedFile($thread, $sessionId, $relativePath, 'whole');
            $this->removeSegments($file);
            $this->writeSegment($file, 0, $content);
            $file->update(['length' => strlen($content), 'sha256' => hash('sha256', $content)]);
        });
    }

    /**
     * First write wins. Returns false when the file already exists.
     */
    public function putImmutable(Thread $thread, string $sessionId, string $relativePath, string $content): bool
    {
        $this->assertValidPath($sessionId, $relativePath);
        if ($this->modeFor($relativePath) !== 'immutable') {
            throw new InvalidArgumentException("{$relativePath} is not an immutable path.");
        }

        return DB::transaction(function () use ($thread, $sessionId, $relativePath, $content): bool {
            $file = $this->lockedFile($thread, $sessionId, $relativePath, 'immutable');
            if ($file->segments()->exists()) {
                return false;
            }
            $this->writeSegment($file, 0, $content);
            $file->update(['length' => strlen($content), 'sha256' => hash('sha256', $content)]);

            return true;
        });
    }

    public function length(Thread $thread, string $sessionId, string $relativePath): int
    {
        $this->assertValidPath($sessionId, $relativePath);

        return (int) $this->fileQuery($thread, $sessionId, $relativePath)->value('length');
    }

    /**
     * Decrypts and joins the file. Returns '' for an unknown file and throws when a
     * segment is missing or fails authentication.
     */
    public function read(Thread $thread, string $sessionId, string $relativePath): string
    {
        $this->assertValidPath($sessionId, $relativePath);
        $file = $this->findFile($thread, $sessionId, $relativePath);

        return $file ? $this->readFile($file) : '';
    }

    /**
     * @return list<array{rel_path: string, mode: string, length: int, sha256: ?string}>
     */
    public function files(Thread $thread, string $sessionId): array
    {
        return array_values(SessionFile::query()
            ->where(['thread_id' => $thread->id, 'session_id' => $sessionId])
            ->orderBy('rel_path')
            ->get()
            ->map(fn (SessionFile $file): array => [
                'rel_path' => $file->rel_path,
                'mode' => $file->mode,
                'length' => (int) $file->length,
                'sha256' => $file->sha256,
            ])
            ->all());
    }

    /**
     * @return list<array{uuid: string, parent_uuid: ?string, rel_path: string, offset: int}>
     */
    public function tree(Thread $thread, string $sessionId): array
    {
        return array_values(DB::table('session_entries')
            ->join('session_files', 'session_files.id', '=', 'session_entries.session_file_id')
            ->where('session_files.thread_id', $thread->id)
            ->where('session_entries.session_id', $sessionId)
            ->orderBy('session_files.rel_path')
            ->orderBy('session_entries.offset')
            ->get(['session_entries.uuid', 'session_entries.parent_uuid', 'session_files.rel_path', 'session_entries.offset'])
            ->map(fn (object $row): array => [
                'uuid' => (string) $row->uuid,
                'parent_uuid' => $row->parent_uuid === null ? null : (string) $row->parent_uuid,
                'rel_path' => (string) $row->rel_path,
                'offset' => (int) $row->offset,
            ])
            ->all());
    }

    public function deleteThread(Thread $thread): void
    {
        SessionFile::query()->where('thread_id', $thread->id)->delete();
        File::deleteDirectory($this->basePath() . '/' . $thread->id);
    }

    /**
     * A session file is <sid>.jsonl, <sid>/subagents/<name>.jsonl|.meta.json or
     * <sid>/tool-results/<path>, where <sid> is a UUID and no segment is "." or "..".
     * Memory files are memory/<name> under the reserved session id.
     */
    public function isValidPath(string $sessionId, string $relativePath): bool
    {
        $name = self::NAME_PATTERN;

        if ($sessionId === self::MEMORY_SESSION_ID) {
            return preg_match("/^memory\\/{$name}$/", $relativePath) === 1 && ! str_contains($relativePath, '..');
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $sessionId) !== 1) {
            return false;
        }

        $id = preg_quote($sessionId, '/');
        $shapes = [
            "/^{$id}\\.jsonl$/",
            "/^{$id}\\/subagents\\/{$name}\\.(jsonl|meta\\.json)$/",
            "/^{$id}\\/tool-results\\/({$name}\\/)*{$name}$/",
        ];

        foreach ($shapes as $shape) {
            if (preg_match($shape, $relativePath) === 1 && ! str_contains($relativePath, '..')) {
                return true;
            }
        }

        return false;
    }

    public function modeFor(string $relativePath): ?string
    {
        return match (true) {
            str_starts_with($relativePath, 'memory/') => 'whole',
            str_contains($relativePath, '/tool-results/') => 'immutable',
            str_ends_with($relativePath, '.meta.json') => 'whole',
            str_ends_with($relativePath, '.jsonl') => 'append',
            default => null,
        };
    }

    private function assertValidPath(string $sessionId, string $relativePath): void
    {
        if (! $this->isValidPath($sessionId, $relativePath)) {
            throw new InvalidArgumentException("{$relativePath} is not a valid session file for session {$sessionId}.");
        }
    }

    private function basePath(): string
    {
        return rtrim((string) config('yak.threads.session_store_path'), '/');
    }

    /**
     * @return Builder<SessionFile>
     */
    private function fileQuery(Thread $thread, string $sessionId, string $relativePath): Builder
    {
        return SessionFile::query()->where(['thread_id' => $thread->id, 'session_id' => $sessionId, 'rel_path' => $relativePath]);
    }

    private function findFile(Thread $thread, string $sessionId, string $relativePath): ?SessionFile
    {
        return $this->fileQuery($thread, $sessionId, $relativePath)->first();
    }

    private function lockedFile(Thread $thread, string $sessionId, string $relativePath, string $mode): SessionFile
    {
        $key = ['thread_id' => $thread->id, 'session_id' => $sessionId, 'rel_path' => $relativePath];
        SessionFile::query()->firstOrCreate($key, ['mode' => $mode]);

        return SessionFile::query()->where($key)->lockForUpdate()->firstOrFail();
    }

    private function writeSegment(SessionFile $file, int $offset, string $bytes): void
    {
        $directory = $this->basePath() . '/' . $file->thread_id . '/' . $file->id;
        File::ensureDirectoryExists($directory, 0700);
        $path = "{$directory}/{$offset}.seg";
        $temporaryPath = "{$path}.tmp";

        File::put($temporaryPath, $this->cipher->encrypt($bytes));
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
     * Decrypts only the segments that overlap [start, end). Throws when a segment
     * file is missing or fails authentication, never returning partial bytes.
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
            if (! File::exists($segment->path)) {
                throw new RuntimeException("Session segment file is missing: {$segment->path}");
            }
            $bytes = $this->cipher->decrypt(File::get($segment->path));
            $from = max(0, $start - $segment->offset);
            $to = min($segment->length, $end - $segment->offset);
            $out .= substr($bytes, $from, $to - $from);
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
