<?php

namespace App\Http\Controllers;

use App\Models\TaskAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

class TaskAttachmentController extends Controller
{
    /**
     * Previewable files are served inline for the lightbox: images, video,
     * audio and PDF under their own type, and anything text-like strictly as
     * `text/plain`, so uploaded HTML or SVG shows as source and never runs on
     * this origin. Everything else, and any `?download=1`, is a download.
     */
    public function __invoke(Request $request, TaskAttachment $attachment): BinaryFileResponse
    {
        abort_unless(Storage::disk('artifacts')->exists($attachment->disk_path), 404, 'Attachment file not found.');

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ];
        $kind = $attachment->previewKind();

        if ($kind === null || $request->boolean('download')) {
            return response()->download($attachment->absolutePath(), $attachment->original_name, [
                ...$headers,
                'Content-Type' => 'application/octet-stream',
            ]);
        }

        return response()->file($attachment->absolutePath(), [
            ...$headers,
            'Content-Type' => $kind === 'text' ? 'text/plain; charset=utf-8' : $attachment->mime_type,
            'Content-Disposition' => HeaderUtils::makeDisposition('inline', $attachment->original_name, Str::ascii($attachment->original_name)),
        ]);
    }
}
