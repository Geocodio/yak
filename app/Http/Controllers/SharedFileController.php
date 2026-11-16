<?php

namespace App\Http\Controllers;

use App\Models\Artifact;
use App\Models\Thread;
use App\Models\YakTask;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * One shared file by its unguessable id. People who can read the thread may always open it; when the file is shared
 * with the team any signed-in user may. Everyone else gets the 404 a missing file gets, and nothing here names the
 * thread, so a teammate without thread access sees the file alone.
 */
class SharedFileController extends Controller
{
    private const array IMAGE_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    public function show(Request $request, string $publicId): Response|BinaryFileResponse
    {
        $file = $this->openable($request, $publicId);

        return match ($file->sharedKind()) {
            'report' => response()->view('shared-files.viewer', [
                'file' => $file,
                'rawUrl' => route('shared-files.raw', ['publicId' => $file->public_id]),
                'downloadUrl' => route('shared-files.raw', ['publicId' => $file->public_id, 'download' => 1]),
            ]),
            'image' => $this->deliver($file, inline: true),
            default => $this->deliver($file, inline: false),
        };
    }

    /**
     * The file's bytes, for the viewer's frame and for downloads. An HTML report is served in a sandbox so a
     * direct visit cannot run its scripts in Yak's origin.
     */
    public function raw(Request $request, string $publicId): BinaryFileResponse
    {
        $file = $this->openable($request, $publicId);

        return $this->deliver($file, inline: ! $request->boolean('download') && in_array($file->sharedKind(), ['report', 'image'], true));
    }

    private function openable(Request $request, string $publicId): Artifact
    {
        $file = Artifact::query()->shared()->where('public_id', $publicId)->first();

        abort_if($file === null, 404);
        abort_unless($file->shared_with_team || $this->canReadThread($request, $file), 404);
        abort_unless(Storage::disk('artifacts')->exists((string) $file->disk_path), 404);

        return $file;
    }

    private function canReadThread(Request $request, Artifact $file): bool
    {
        $task = YakTask::query()->withoutGlobalScopes()->find($file->yak_task_id);
        $thread = $task?->thread_id === null ? null : Thread::query()->withoutGlobalScopes()->find($task->thread_id);

        return $thread !== null && Gate::forUser($request->user())->allows('view', $thread);
    }

    private function deliver(Artifact $file, bool $inline): BinaryFileResponse
    {
        $extension = strtolower(pathinfo($file->filename, PATHINFO_EXTENSION));
        $headers = ['Content-Type' => self::IMAGE_TYPES[$extension] ?? match ($extension) {
            'html' => 'text/html; charset=utf-8',
            'csv' => 'text/csv',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        }, 'X-Content-Type-Options' => 'nosniff'];

        if ($extension === 'html') {
            $headers['Content-Security-Policy'] = 'sandbox';
        }

        $response = response()->file(Storage::disk('artifacts')->path((string) $file->disk_path), $headers);
        $response->setContentDisposition($inline ? 'inline' : 'attachment', $file->filename);

        return $response;
    }
}
