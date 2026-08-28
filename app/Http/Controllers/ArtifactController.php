<?php

namespace App\Http\Controllers;

use App\Models\Artifact;
use App\Models\YakTask;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArtifactController extends Controller
{
    public function show(Request $request, YakTask $task, string $filename): BinaryFileResponse|StreamedResponse
    {
        $artifact = $task->artifacts()
            ->where('filename', $filename)
            ->firstOrFail();

        if (! $request->hasValidSignature() && ! $request->user()) {
            abort(403, 'Invalid or expired signed URL.');
        }

        abort_unless(Storage::disk('artifacts')->exists($artifact->disk_path), 404, 'Artifact file not found.');

        $mimeType = $this->guessMimeType($filename);

        return response()->file(
            Storage::disk('artifacts')->path($artifact->disk_path),
            ['Content-Type' => $mimeType],
        );
    }

    public function viewer(Request $request, YakTask $task, string $filename): Response
    {
        $artifact = $task->artifacts()
            ->where('filename', $filename)
            ->firstOrFail();

        abort_unless((bool) $request->user(), 403);

        return response()->view('artifacts.viewer', [
            'task' => $task,
            'artifact' => $artifact,
        ]);
    }

    /**
     * Permanent, unsigned delivery for the two image roles a PR body
     * embeds. GitHub's camo proxy re-fetches these long after a signed
     * URL would have expired, so the token is the only credential and
     * the route refuses every other role. Nothing here lists artifacts.
     */
    public function publicImage(string $token): BinaryFileResponse
    {
        $artifact = Artifact::query()
            ->where('public_token', $token)
            ->whereIn('role', Artifact::PUBLIC_ROLES)
            ->first();

        abort_if($artifact === null, 404);
        abort_unless(Storage::disk('artifacts')->exists($artifact->disk_path), 404);

        return response()->file(
            Storage::disk('artifacts')->path($artifact->disk_path),
            [
                'Content-Type' => $this->guessMimeType($artifact->filename),
                'Cache-Control' => 'public, max-age=31536000',
            ],
        );
    }

    private function guessMimeType(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'html' => 'text/html',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
}
