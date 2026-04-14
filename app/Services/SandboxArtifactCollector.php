<?php

namespace App\Services;

use App\Models\YakTask;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Collects .yak-artifacts/ from a sandbox container to host storage.
 *
 * Must be called BEFORE the sandbox is destroyed, since the files
 * only exist inside the container's filesystem.
 */
class SandboxArtifactCollector
{
    /**
     * Pull all artifacts from the sandbox to the local artifacts disk.
     *
     * Files are stored at {task_id}/{filename} on the artifacts disk,
     * ready for ProcessCIResultJob to process them.
     */
    public static function collect(IncusSandboxManager $sandbox, string $containerName, YakTask $task): void
    {
        $workspacePath = (string) config('yak.sandbox.workspace_path', '/workspace');
        $remotePath = "{$workspacePath}/.yak-artifacts";

        if (! $sandbox->fileExists($containerName, $remotePath)) {
            return;
        }

        $localDir = Storage::disk('artifacts')->path((string) $task->id);

        if (! is_dir($localDir)) {
            mkdir($localDir, 0755, true);
        }

        try {
            $sandbox->pullDirectory($containerName, $remotePath, $localDir);

            Log::channel('yak')->info('Artifacts collected from sandbox', [
                'task_id' => $task->id,
                'container' => $containerName,
                'local_dir' => $localDir,
            ]);
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('Failed to collect artifacts from sandbox', [
                'task_id' => $task->id,
                'container' => $containerName,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
