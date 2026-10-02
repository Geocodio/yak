<?php

namespace App\Jobs\Concerns;

use App\Enums\NotificationType;
use App\Jobs\SendNotificationJob;
use App\Models\Artifact;
use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\Storage;

/**
 * Shared by the jobs that run a read-only research turn: collecting the
 * optional HTML report from the sandbox and sending the answer through
 * SendNotificationJob. Expects a `$task` property on the job.
 */
trait ReportsResearchToSource
{
    protected function collectHtmlArtifact(IncusSandboxManager $sandbox, string $containerName): ?Artifact
    {
        $workspacePath = IncusSandboxManager::workspacePath();
        $remotePath = "{$workspacePath}/.yak-artifacts/research.html";

        if (! $sandbox->fileExists($containerName, $remotePath)) {
            return null;
        }

        // Pull the artifact from the sandbox to local storage
        $storagePath = "{$this->task->id}/research.html";
        $localPath = Storage::disk('artifacts')->path($storagePath);

        $localDir = dirname($localPath);
        if (! is_dir($localDir)) {
            mkdir($localDir, 0755, true);
        }

        $sandbox->pullFile($containerName, $remotePath, $localPath);

        /** Research artifacts sit outside the video pipeline, so they carry no role. */
        return Artifact::create([
            'yak_task_id' => $this->task->id,
            'type' => 'research',
            'role' => null,
            'filename' => 'research.html',
            'disk_path' => $storagePath,
            'size_bytes' => filesize($localPath) ?: 0,
        ]);
    }

    /**
     * Auth-gated viewer URL — same one we attach to Linear issues. The
     * controller redirects unauthenticated visitors through the dashboard
     * login, so a Slack click works once the user has a session.
     */
    protected function viewerUrl(Artifact $artifact): string
    {
        return route('artifacts.viewer', [
            'task' => $this->task->id,
            'filename' => $artifact->filename,
        ]);
    }

    /**
     * Send the research answer. The message is already in the Yak voice with
     * the report link appended, so it skips the personality rewrite that could
     * paraphrase the link away. For Linear, the notification driver also moves
     * an issue without a pull request to Done.
     */
    protected function reportResult(string $message): void
    {
        SendNotificationJob::dispatch($this->task, NotificationType::Result, $message, personalize: false);
    }
}
