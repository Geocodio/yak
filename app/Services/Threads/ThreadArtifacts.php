<?php

namespace App\Services\Threads;

use App\Jobs\CreatePullRequestJob;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\ThreadTurnMessage;
use App\Models\YakTask;
use App\Services\ArtifactPersister;
use App\Services\SandboxArtifactCollector;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Moves what Claude recorded into `.yak-artifacts/` during a turn from the
 * thread's sandbox onto the thread's task, where the pull request body and
 * the render jobs already look for it. Everything but `shared/` is removed from the
 * sandbox once persisted, so each turn only collects its own recording; shared files stay
 * and are collected again each turn, which changes nothing unless their content did.
 */
class ThreadArtifacts
{
    /** Roles that make up one recording; a newer recording archives what it shows and replaces all of them. */
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

        $workspacePath = $thread->primaryRepository->sandbox_path ?? '/workspace';
        $container = (string) $thread->sandbox_container;
        $shell = ThreadShell::for($thread);

        SandboxArtifactCollector::collect($shell, $container, $task, $workspacePath);

        $collected = Storage::disk('artifacts')->path("{$task->id}/.yak-artifacts");

        if (! is_dir($collected)) {
            return;
        }

        $this->dropSupersededRecording($thread, $task, $collected);
        $artifacts = ArtifactPersister::persist($task);
        $this->anchorSharedFiles($thread, $artifacts);

        // `shared/` stays so Claude can open and edit its own files next turn; every other capture folder is cleared.
        $shell->run($container, 'find ' . escapeshellarg("{$workspacePath}/.yak-artifacts") . ' -mindepth 1 -maxdepth 1 ! -name shared -exec rm -rf {} +');

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
        $branch = $task->branch_name ?? $thread->branch;

        if (! $isRequested || $task === null || blank($branch) || $task->pr_url !== null) {
            return;
        }

        if ($task->branch_name === null) {
            $task->update(['branch_name' => $branch]);
        }

        CreatePullRequestJob::dispatch($task);
    }

    /**
     * A turn's shared files sit in the conversation after the last event the thread had when they were collected.
     *
     * @param  array<int, Artifact>  $artifacts
     */
    private function anchorSharedFiles(Thread $thread, array $artifacts): void
    {
        $shared = array_filter($artifacts, fn (Artifact $artifact): bool => $artifact->role === Artifact::SHARED_ROLE);
        $sequence = ThreadEvent::query()->where('thread_id', $thread->id)->max('sequence');

        foreach ($shared as $artifact) {
            $artifact->update(['anchor_sequence' => $sequence]);
        }

        // A re-saved file keeps its first card; the version recorded by this collection sits at the same point.
        ArtifactVersion::query()
            ->whereNull('anchor_sequence')
            ->whereIn('artifact_id', Artifact::query()->shared()->whereIn('yak_task_id', $thread->tasks()->select('id'))->select('id'))
            ->update(['anchor_sequence' => $sequence]);
    }

    private function dropSupersededRecording(Thread $thread, YakTask $task, string $collected): void
    {
        if (! File::exists("{$collected}/script.json")) {
            return;
        }

        app(ThreadWalkthroughs::class)->beginRecording($thread, $task);

        foreach ($task->artifacts()->whereIn('role', self::RECORDING_ROLES)->get() as $artifact) {
            Storage::disk('artifacts')->delete((string) $artifact->disk_path);
            $artifact->delete();
        }
    }
}
