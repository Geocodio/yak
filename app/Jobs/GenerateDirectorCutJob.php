<?php

namespace App\Jobs;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\Models\Artifact;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\SandboxArtifactCollector;
use App\Services\TaskLogger;
use App\YakPromptBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Spin up a fresh sandbox on the PR branch and record a Director's Cut
 * walkthrough. Mirrors the RunYakJob sandbox lifecycle but narrower:
 * no commit/push, no CI wait. Just check out the PR branch, run the agent
 * in the 'director' tier, pull the .yak-artifacts/ back out, and hand the
 * captured webm to RenderVideoJob for post-processing.
 */
class GenerateDirectorCutJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $taskId) {}

    public function handle(IncusSandboxManager $sandbox, AgentRunner $agent): void
    {
        $task = YakTask::findOrFail($this->taskId);

        if ($task->branch_name === null || $task->branch_name === '') {
            throw new RuntimeException("task {$task->id} has no PR branch — Director's Cut requires an open PR");
        }

        $repository = Repository::where('slug', $task->repo)->firstOrFail();

        $task->update(['director_cut_status' => 'rendering']);

        $containerName = null;

        try {
            $containerName = $sandbox->create($task, $repository);
            TaskLogger::info($task, "Director's Cut sandbox created", ['container' => $containerName]);

            $this->prepareBranch($sandbox, $containerName, $task);

            $result = $agent->run(new AgentRunRequest(
                prompt: YakPromptBuilder::taskPrompt($task, self::parseMetadata($task->context)),
                systemPrompt: YakPromptBuilder::systemPrompt($task, tier: 'director'),
                containerName: $containerName,
                timeoutSeconds: $this->timeout - 30,
                maxBudgetUsd: (float) config('yak.max_budget_per_task'),
                maxTurns: (int) config('yak.max_turns'),
                model: (string) config('yak.default_model'),
                resumeSessionId: null,
                mcpConfigPath: config('yak.mcp_config_path'),
                task: $task,
            ));

            if ($result->isError) {
                throw new RuntimeException("Director's Cut agent reported error: {$result->resultSummary}");
            }

            SandboxArtifactCollector::collect($sandbox, $containerName, $task);

            $webm = $this->registerWebmArtifact($task);

            if ($webm !== null) {
                // RenderVideoJob transitions director_cut_status to 'ready'
                // once the MP4 has finished rendering and the PR body has
                // been patched with the link. Leaving the status at
                // 'rendering' here keeps the state machine linear:
                // null → rendering → ready (or failed).
                RenderVideoJob::dispatch($webm->id, 'director');
            } else {
                Log::channel('yak')->warning('GenerateDirectorCutJob: no director-cut.webm produced', [
                    'task_id' => $task->id,
                ]);
                $task->update(['director_cut_status' => 'failed']);
            }
        } catch (Throwable $e) {
            $task->update(['director_cut_status' => 'failed']);
            Log::channel('yak')->error('GenerateDirectorCutJob failed', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            if ($containerName !== null) {
                try {
                    $sandbox->destroy($containerName);
                } catch (Throwable) {
                    // Swallow destroy failures — stale sandboxes are
                    // reclaimed by IncusSandboxManager::cleanupStale().
                }
            }
        }
    }

    public function failed(Throwable $e): void
    {
        YakTask::where('id', $this->taskId)->update(['director_cut_status' => 'failed']);
    }

    /**
     * Configure git inside the sandbox and check out the PR branch.
     * Mirrors the RetryYakJob pattern (fetch + checkout existing branch)
     * rather than RunYakJob's (create new branch off default).
     */
    private function prepareBranch(IncusSandboxManager $sandbox, string $containerName, YakTask $task): void
    {
        $workspacePath = (string) config('yak.sandbox.workspace_path', '/workspace');
        $branchName = (string) $task->branch_name;

        $this->configureGitInSandbox($sandbox, $containerName);

        $sandbox->run($containerName, "cd {$workspacePath} && git fetch origin {$branchName}", timeout: 60);
        $sandbox->run($containerName, "cd {$workspacePath} && git checkout {$branchName}", timeout: 30);
    }

    private function configureGitInSandbox(IncusSandboxManager $sandbox, string $containerName): void
    {
        $gitName = config('yak.git_user_name', 'Yak');
        $gitEmail = config('yak.git_user_email', 'yak@noreply.github.com');

        $sandbox->run($containerName, 'git config --global user.name ' . escapeshellarg($gitName), timeout: 10);
        $sandbox->run($containerName, 'git config --global user.email ' . escapeshellarg($gitEmail), timeout: 10);

        $installationId = (int) config('yak.channels.github.installation_id');

        if (! $installationId) {
            return;
        }

        $token = app(GitHubAppService::class)->getInstallationToken($installationId);

        $sandbox->run(
            $containerName,
            'git config --global credential.https://github.com.helper ' .
            escapeshellarg("!f() { echo \"protocol=https\nhost=github.com\nusername=x-access-token\npassword={$token}\"; }; f"),
            timeout: 10,
        );
    }

    /**
     * Look for director-cut.webm in the task's artifact dir after collection
     * and register it as a video Artifact so RenderVideoJob can pick it up.
     */
    private function registerWebmArtifact(YakTask $task): ?Artifact
    {
        $disk = Storage::disk('artifacts');
        $filename = 'director-cut.webm';
        $diskPath = "{$task->id}/{$filename}";

        if (! $disk->exists($diskPath)) {
            // SandboxArtifactCollector may have landed the file under the
            // nested .yak-artifacts/ subdir — promote it to the task dir
            // root so RenderVideoJob (which computes `dirname($disk_path)`
            // for the storyboard lookup) can find the sibling storyboard.
            $nested = "{$task->id}/.yak-artifacts/{$filename}";
            if ($disk->exists($nested)) {
                $disk->move($nested, $diskPath);
            } else {
                return null;
            }
        }

        $storyboardNested = "{$task->id}/.yak-artifacts/storyboard.json";
        $storyboardFlat = "{$task->id}/storyboard.json";
        if (! $disk->exists($storyboardFlat) && $disk->exists($storyboardNested)) {
            $disk->move($storyboardNested, $storyboardFlat);
        }

        return Artifact::create([
            'yak_task_id' => $task->id,
            'type' => 'video',
            'filename' => $filename,
            'disk_path' => $diskPath,
            'size_bytes' => $disk->size($diskPath),
        ]);
    }

    /**
     * Parse task context as JSON metadata, returning an empty array for
     * plain-text or null. Mirrors RunYakJob's parser so the director cut
     * agent sees the same shaped prompt input.
     *
     * @return array<string, mixed>
     */
    private static function parseMetadata(?string $context): array
    {
        if ($context === null || $context === '') {
            return [];
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($context, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        return [];
    }
}
