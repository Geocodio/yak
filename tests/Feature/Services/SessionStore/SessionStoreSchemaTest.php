<?php

use App\Models\SessionEntry;
use App\Models\SessionFile;
use App\Models\SessionFileSegment;
use App\Models\Thread;
use Illuminate\Database\UniqueConstraintViolationException;

function sessionFileFor(Thread $thread): SessionFile
{
    return SessionFile::query()->create([
        'thread_id' => $thread->id,
        'session_id' => 'a0a0a0a0-0000-4000-8000-000000000001',
        'rel_path' => 'a0a0a0a0-0000-4000-8000-000000000001.jsonl',
        'mode' => 'append',
    ]);
}

it('cascades files, segments and entries when a thread is deleted', function () {
    $thread = Thread::factory()->create();
    $file = sessionFileFor($thread);
    SessionFileSegment::query()->create(['session_file_id' => $file->id, 'offset' => 0, 'length' => 1, 'sha256' => str_repeat('a', 64), 'path' => '/x']);
    SessionEntry::query()->create(['session_id' => $file->session_id, 'uuid' => 'u1', 'session_file_id' => $file->id, 'offset' => 0]);

    expect(SessionFile::count())->toBe(1)->and(SessionFileSegment::count())->toBe(1)->and(SessionEntry::count())->toBe(1);

    $thread->delete();

    expect(SessionFile::count())->toBe(0)->and(SessionFileSegment::count())->toBe(0)->and(SessionEntry::count())->toBe(0);
});

it('keeps (session_id, uuid) entries unique per file', function () {
    $file = sessionFileFor(Thread::factory()->create());
    $attributes = ['session_id' => $file->session_id, 'uuid' => 'u1', 'session_file_id' => $file->id, 'offset' => 0];
    SessionEntry::query()->create($attributes);

    expect(fn () => SessionEntry::query()->create($attributes))->toThrow(UniqueConstraintViolationException::class);
});
