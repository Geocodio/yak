<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Http\Resources\ThreadDiffData;
use App\Models\Thread;
use App\Models\ThreadDiffFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ThreadDiffController extends Controller
{
    public function show(Thread $thread): JsonResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);

        return response()->json(ThreadDiffData::build($thread));
    }

    public function showFile(Thread $thread, int $file): JsonResponse
    {
        abort_unless(Gate::allows('view', $thread), 404);

        $diffFile = ThreadDiffFile::query()
            ->whereKey($file)
            ->whereHas('diff', fn ($query) => $query->where('thread_id', $thread->id))
            ->firstOrFail();

        return response()->json([
            'path' => $diffFile->path,
            'oldPath' => $diffFile->old_path,
            'status' => $diffFile->status->value,
            'isBinary' => $diffFile->is_binary,
            'isTooLarge' => $diffFile->is_too_large,
            'patch' => $diffFile->patch,
        ]);
    }
}
