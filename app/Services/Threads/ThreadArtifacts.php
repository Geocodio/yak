<?php

namespace App\Services\Threads;

use App\Jobs\CreatePullRequestJob;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\YakTask;
use App\Services\ArtifactPersister;
use App\Services\SandboxArtifactCollector;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Moves what Claude recorded into `.yak-artifacts/` during a turn from the
 * thread's sandbox onto the thread's task, where the pull request body and
 * the render jobs already look for it. The folder is removed from the sandbox
 * once persisted, so each turn only collects its own recording.
 */
class ThreadArtifacts
{
    /** Roles that make up one recording; a newer recording replaces all of them. */
    private const array RECORDING_ROLES = ['script', 'manifest', 'shot', 'still', 'voiceover', 'screenshot'];

    public function collect(Thread $thread): void
    {
        if ($thread->sharesParentSandbox()) {
            return;
        }

        $task = $thread->pullRequestTask();

        if ($task === null) {
            return;
        }

        $workspacePath = $thread->primaryRepository?->sandbox_path ?? '/workspace';
        $container = (string) $thread->sandbox_container;
        $shell = ThreadShell::for($thread);

        SandboxArtifactCollector::collect($shell, $container, $task, $workspacePath);

        $collected = Storage::disk('artifacts')->path("{$task->id}/.yak-artifacts");

        if (! is_dir($collected)) {
            return;
        }

        $this->dropSupersededRecording($task, $collected);
        $artifacts = ArtifactPersister::persist($task);

        $shell->run($container, 'rm -rf ' . escapeshellarg("{$workspacePath}/.yak-artifacts"));

        // A PR that is already open gets the new screenshots in its body; the render job updates the video itself.
        if ($task->pr_url !== null && collect($artifacts)->contains('role', 'screenshot')) {
            CreatePullRequestJob::dispatch($task, refreshOnly: true);
        }
    }

    /**
     * "Record walkthrough & open PR" opens the pull request once its turn has completed and the recording is collected.
     */
    public function openPullRequestIfRequested(Thread $thread, TaskRun $run): void
    {
        $isRequested = ThreadTurnMessage::query()
            ->where('task_run_id', $run->id)
            ->where('intent', 'record_walkthrough_and_open_pr')
            ->exists();
        $task = $thread->pullRequestTask();
        $branch = $task?->branch_name ?? $thread->branch;

        if (! $isRequested || $task === null || blank($branch) || $task->pr_url !== null) {
            return;
        }

        if ($task->branch_name === null) {
            $task->update(['branch_name' => $branch]);
        }

        CreatePullRequestJob::dispatch($task);
    }

    private function dropSupersededRecording(YakTask $task, string $collected): void
    {
        if (! File::exists("{$collected}/script.json")) {
            return;
        }

        foreach ($task->artifacts()->whereIn('role', self::RECORDING_ROLES)->get() as $artifact) {
            Storage::disk('artifacts')->delete((string) $artifact->disk_path);
            $artifact->delete();
        }
    }
}
