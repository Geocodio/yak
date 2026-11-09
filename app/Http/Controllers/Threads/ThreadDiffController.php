<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadDiffFile;
use Illuminate\Http\JsonResponse;

class ThreadDiffController extends Controller
{
    public function show(Thread $thread): JsonResponse
    {
        $diff = ThreadDiff::query()->where('thread_id', $thread->id)->with('files')->first();

        if ($diff === null) {
            return response()->json(['diff' => null]);
        }

        return response()->json(['diff' => [
            'baseSha' => $diff->base_sha,
            'headSha' => $diff->head_sha,
            'capturedAt' => $diff->captured_at->toIso8601String(),
            'isTruncated' => $diff->is_truncated,
            'isTurnRunning' => $thread->runningTurn() !== null,
            'files' => $diff->files->map(fn (ThreadDiffFile $file): array => [
                'id' => $file->id,
                'path' => $file->path,
                'oldPath' => $file->old_path,
                'status' => $file->status->value,
                'additions' => $file->additions,
                'deletions' => $file->deletions,
                'isBinary' => $file->is_binary,
                'isTooLarge' => $file->is_too_large,
            ])->all(),
        ]]);
    }

    public function showFile(Thread $thread, int $file): JsonResponse
    {
        $diffFile = ThreadDiffFile::query()
            ->whereKey($file)
            ->whereHas('diff', fn ($query) => $query->where('thread_id', $thread->id))
            ->firstOrFail();

        return response()->json([
            'path' => $diffFile->path,
            'status' => $diffFile->status->value,
            'isBinary' => $diffFile->is_binary,
            'isTooLarge' => $diffFile->is_too_large,
            'patch' => $diffFile->patch,
        ]);
    }
}
