<?php

namespace App\Services\Threads;

use App\Models\Artifact;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\ThreadWalkthrough;
use App\Models\VideoMetric;
use App\Models\YakTask;
use App\Services\WalkthroughPrSection;
use Illuminate\Support\Facades\Storage;

/**
 * Numbers a thread's recordings. A new recording copies the one before it into
 * an archive first, so each version keeps playing where it was made in the
 * thread. The live artifacts stay in place until the new render replaces them,
 * so an open pull request never links to a missing video.
 */
class ThreadWalkthroughs
{
    /** @var array<string, string> Live role => the role of its archived copy */
    public const array ARCHIVED_ROLES = ['cut' => 'archived_cut', 'thumbnail' => 'archived_thumbnail', 'screenshot' => 'archived_screenshot'];

    public function beginRecording(Thread $thread, YakTask $task): ThreadWalkthrough
    {
        $this->archiveCurrent($thread, $task);

        return ThreadWalkthrough::query()->create([
            'thread_id' => $thread->id,
            'yak_task_id' => $task->id,
            'number' => $this->nextNumber($thread),
            'anchor_sequence' => ThreadEvent::query()->where('thread_id', $thread->id)->max('sequence'),
        ]);
    }

    public function current(Thread $thread): ?ThreadWalkthrough
    {
        return ThreadWalkthrough::query()->where('thread_id', $thread->id)->whereNull('archived_at')->orderByDesc('number')->first();
    }

    public function nextNumber(Thread $thread): int
    {
        return (int) ThreadWalkthrough::query()->where('thread_id', $thread->id)->max('number') + 1;
    }

    /**
     * The last thread event at the moment a recording made before numbering existed was stored.
     */
    public function sequenceAt(Thread $thread, ?\DateTimeInterface $recordedAt): ?int
    {
        if ($recordedAt === null) {
            return null;
        }

        $sequence = ThreadEvent::query()->where('thread_id', $thread->id)->where('created_at', '<=', $recordedAt)->max('sequence');

        return $sequence === null ? null : (int) $sequence;
    }

    private function archiveCurrent(Thread $thread, YakTask $task): void
    {
        $current = $this->current($thread);
        $artifacts = $task->artifacts()->whereIn('role', array_keys(self::ARCHIVED_ROLES))->orderBy('id')->get();

        if ($artifacts->isEmpty()) {
            $current?->delete();

            return;
        }

        $walkthrough = $current ?? ThreadWalkthrough::query()->create([
            'thread_id' => $thread->id,
            'yak_task_id' => $task->id,
            'number' => $this->nextNumber($thread),
            'anchor_sequence' => $this->sequenceAt($thread, $artifacts->first()->created_at),
        ]);

        foreach (self::ARCHIVED_ROLES as $role => $archivedRole) {
            foreach ($artifacts->where('role', $role) as $artifact) {
                $this->archive($artifact, $walkthrough, $archivedRole);
            }
        }

        $walkthrough->update([
            'duration_seconds' => VideoMetric::query()->where('yak_task_id', $task->id)->where('status', VideoMetric::STATUS_RENDERED)->latest('id')->value('duration_seconds'),
            'chapters' => array_map(
                fn (array $chapter): array => ['title' => $chapter['title'], 'startSeconds' => $chapter['startSeconds']],
                WalkthroughPrSection::chaptersForTask($task),
            ),
            'archived_at' => now(),
        ]);
    }

    /**
     * The copy's `file` type keeps it out of every lookup that selects live media by type.
     */
    private function archive(Artifact $artifact, ThreadWalkthrough $walkthrough, string $archivedRole): void
    {
        $disk = Storage::disk('artifacts');

        if (! $disk->exists((string) $artifact->disk_path)) {
            return;
        }

        $filename = "walkthrough-{$walkthrough->number}-{$artifact->filename}";
        $path = "{$artifact->yak_task_id}/walkthroughs/{$walkthrough->number}/{$artifact->filename}";

        $disk->copy((string) $artifact->disk_path, $path);

        Artifact::query()->create([
            'yak_task_id' => $artifact->yak_task_id,
            'thread_walkthrough_id' => $walkthrough->id,
            'type' => 'file',
            'role' => $archivedRole,
            'filename' => $filename,
            'disk_path' => $path,
            'size_bytes' => $artifact->size_bytes,
            'caption' => $artifact->caption,
        ]);
    }
}
