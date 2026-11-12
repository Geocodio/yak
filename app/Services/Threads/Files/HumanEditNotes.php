<?php

namespace App\Services\Threads\Files;

use App\Models\Thread;
use App\Models\ThreadFileEdit;
use App\Models\User;
use App\Services\Teleport\ThreadNotOwnedByYak;
use App\Services\Threads\ThreadTurns;

/**
 * Tells the agent which files the person edited, as a steer. A file whose
 * earlier note has not reached the agent yet is not announced twice.
 */
class HumanEditNotes
{
    public function __construct(private readonly ThreadTurns $turns) {}

    public function record(Thread $thread, User $user, string $absolutePath, string $sha256): ThreadFileEdit
    {
        $edit = ThreadFileEdit::query()->create([
            'thread_id' => $thread->id,
            'user_id' => $user->id,
            'path' => $absolutePath,
            'content_sha256' => $sha256,
        ]);

        $covered = ThreadFileEdit::query()
            ->where('thread_id', $thread->id)
            ->whereHas('pendingSteeringMessage', fn ($query) => $query->whereNull('delivered_at')->whereNull('absorbed_at'))
            ->with('pendingSteeringMessage')
            ->get()
            ->keyBy('path');

        $unnoted = ThreadFileEdit::query()->where('thread_id', $thread->id)->whereNull('noted_at')->get();
        $paths = array_values(array_filter(
            array_unique($unnoted->pluck('path')->all()),
            fn (string $path): bool => ! $covered->has($path),
        ));

        $messageId = null;

        try {
            if ($paths !== []) {
                $messageId = $this->turns->steer($thread, self::wrap($paths), $user, null)->id;
            }
        } catch (ThreadNotOwnedByYak) {
            return $edit;
        }

        foreach ($unnoted as $pending) {
            $pending->forceFill([
                'noted_at' => now(),
                'pending_steering_message_id' => $messageId ?? $covered->get($pending->path)?->pending_steering_message_id,
            ])->save();
        }

        return $edit->refresh();
    }

    /**
     * @param  list<string>  $paths
     */
    public static function wrap(array $paths): string
    {
        $workspace = rtrim((string) config('yak.sandbox.workspace_path'), '/') . '/';
        $relative = array_map(fn (string $path): string => str_starts_with($path, $workspace) ? substr($path, strlen($workspace)) : $path, $paths);

        return "The person edited these files while you were working. Re-read them before you continue:\n- " . implode("\n- ", $relative);
    }
}
