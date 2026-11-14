<?php

namespace App\Services\Threads;

use App\Models\Thread;
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

        $task = $thread->tasks()->whereNotNull('branch_name')->latest('id')->first()
            ?? $thread->tasks()->latest('id')->first();

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
        ArtifactPersister::persist($task);

        $shell->run($container, 'rm -rf ' . escapeshellarg("{$workspacePath}/.yak-artifacts"));
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
