<?php

namespace App\Http\Controllers;

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
        \Log::info('ArtifactController::show', [
            'task_id' => $task->id,
            'filename' => $filename,
            'artifact_count' => $task->artifacts()->count(),
            'scheme' => $request->getScheme(),
            'url' => $request->fullUrl(),
        ]);

        $artifact = $task->artifacts()
            ->where('filename', $filename)
            ->firstOrFail();

        if (! $request->hasValidSignature() && ! $request->user()) {
            abort(403, 'Invalid or expired signed URL.');
        }

        $path = $artifact->disk_path;

        if (Storage::disk('local')->exists($path)) {
            $mimeType = $this->guessMimeType($filename);

            return response()->file(
                Storage::disk('local')->path($path),
                ['Content-Type' => $mimeType]
            );
        }

        if (file_exists($path)) {
            $mimeType = $this->guessMimeType($filename);

            return response()->file($path, ['Content-Type' => $mimeType]);
        }

        abort(404, 'Artifact file not found.');
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
