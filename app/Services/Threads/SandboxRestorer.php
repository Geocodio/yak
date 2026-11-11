<?php

namespace App\Services\Threads;

use App\Enums\RestoreStepName;
use App\Enums\RestoreStepStatus;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadSandboxState;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRestoreStep;
use App\Services\Datasets\DatasetAvailability;
use App\Services\Datasets\DatasetClones;
use App\Services\IncusSandboxManager;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * Rebuilds a thread sandbox in six recorded steps. A retry resumes at the
 * first step that did not finish; an earlier restore that completed starts over.
 */
class SandboxRestorer
{
    private const OUTPUT_LIMIT = 65536;

    /** @var list<string> */
    private array $secrets = [];

    public function __construct(
        private SandboxManager $sandboxes,
        private RefreshTriggers $refresh,
        private SessionFileSync $sessions,
        private EnvironmentNote $note,
        private DatasetClones $datasetClones,
    ) {}

    public function restore(Thread $thread): bool
    {
        $this->secrets = array_values(array_filter($this->refresh->buildEnvironment($thread), fn (string $value): bool => $value !== ''));

        foreach ($this->stepsFor($thread) as $step) {
            if (in_array($step->status, [RestoreStepStatus::Succeeded, RestoreStepStatus::Skipped], true)) {
                continue;
            }

            $step->update(['status' => RestoreStepStatus::Running, 'output' => null, 'error' => null, 'started_at' => now(), 'finished_at' => null]);

            try {
                $ran = $this->perform($step, $thread);
                $step->update(['status' => $ran ? RestoreStepStatus::Succeeded : RestoreStepStatus::Skipped, 'finished_at' => now()]);
            } catch (Throwable $exception) {
                $step->update(['status' => RestoreStepStatus::Failed, 'error' => $this->scrub($exception->getMessage()), 'finished_at' => now()]);
                $thread->update(['blocked_reason' => ThreadBlockReason::RestoreFailed, 'blocked_at' => $thread->blocked_at ?? now()]);

                return false;
            }
        }

        if ($thread->blocked_reason === ThreadBlockReason::RestoreFailed) {
            $thread->update(['blocked_reason' => null, 'blocked_at' => null]);
        }

        return true;
    }

    /**
     * @return Collection<int, ThreadRestoreStep>
     */
    private function stepsFor(Thread $thread): Collection
    {
        $steps = $thread->restoreSteps()->get();
        $isFinished = fn (ThreadRestoreStep $step): bool => in_array($step->status, [RestoreStepStatus::Succeeded, RestoreStepStatus::Skipped], true);

        if ($steps->isNotEmpty() && ! $steps->every($isFinished)) {
            return $steps;
        }

        $thread->restoreSteps()->delete();

        return collect(RestoreStepName::cases())->map(fn (RestoreStepName $name): ThreadRestoreStep => $thread->restoreSteps()->create([
            'position' => $name->position(),
            'step' => $name,
            'status' => RestoreStepStatus::Pending,
        ]));
    }

    /** Returns false when the step had no work to do. */
    private function perform(ThreadRestoreStep $step, Thread $thread): bool
    {
        return match ($step->step) {
            RestoreStepName::CreateSandbox => $this->createSandbox($thread),
            RestoreStepName::CheckoutBranch => $this->checkoutBranch($step, $thread),
            RestoreStepName::RunSetupScript => $this->runSetupScript($step, $thread),
            RestoreStepName::RunRefreshTriggers => $this->runRefreshTriggers($step, $thread),
            RestoreStepName::RestoreSessionFiles => $this->restoreSessionFiles($thread),
            RestoreStepName::PrepareEnvironment => $this->prepareEnvironment($step, $thread),
        };
    }

    private function createSandbox(Thread $thread): bool
    {
        $this->sandboxes->create($thread);
        $this->attachDataset($thread);
        $this->sandboxes->start($thread);

        $values = ['sandbox_state' => ThreadSandboxState::Running->value, 'sandbox_state_changed_at' => now()];

        if ($thread->sandbox_container !== null) {
            Thread::withoutGlobalScopes()->where('sandbox_container', $thread->sandbox_container)->update($values);
        }

        $thread->forceFill($values)->save();
        $thread->refresh();

        return true;
    }

    /** A missing or failed dataset clone never blocks the restore. */
    private function attachDataset(Thread $thread): void
    {
        $repository = Repository::where('slug', $thread->repo)->first();

        if ($repository !== null) {
            $this->datasetClones->attach($thread, $repository, $thread->sandbox_container ?? 'yak-thread-' . $thread->id);
        }
    }

    private function checkoutBranch(ThreadRestoreStep $step, Thread $thread): bool
    {
        $this->configureGit($thread);

        $branch = (string) $thread->branch;

        if ($branch === '') {
            return false;
        }

        $fetch = $this->run($step, $thread, ['git', 'fetch', 'origin', $branch]);

        if ($fetch->successful()) {
            $this->mustRun($step, $thread, ['git', 'checkout', '-B', $branch, 'FETCH_HEAD']);
        } elseif (str_contains($fetch->errorOutput(), "couldn't find remote ref")) {
            // The branch was never pushed, so it starts from the template's checkout.
            $this->mustRun($step, $thread, ['git', 'checkout', '-B', $branch]);
        } else {
            throw new RuntimeException("git fetch failed (exit {$fetch->exitCode()})");
        }

        return true;
    }

    private function runSetupScript(ThreadRestoreStep $step, Thread $thread): bool
    {
        if ($this->sandboxes->exec($thread, ['test', '-f', '.yak/setup.sh'])->failed()) {
            return false;
        }

        $this->mustRun($step, $thread, ['bash', '.yak/setup.sh'], $this->refresh->buildEnvironment($thread));

        return true;
    }

    private function runRefreshTriggers(ThreadRestoreStep $step, Thread $thread): bool
    {
        $results = $this->refresh->run($thread);

        foreach ($results as $result) {
            $this->append($step, "$ {$result->command}\n{$result->output}\n");
        }

        if ($results === []) {
            return false;
        }

        $failed = end($results);

        if (! $failed->succeeded()) {
            throw new RuntimeException("Refresh command failed (exit {$failed->exitCode}): {$failed->command}");
        }

        return true;
    }

    private function restoreSessionFiles(Thread $thread): bool
    {
        $this->sessions->restoreIntoSandbox($thread);

        return true;
    }

    private function prepareEnvironment(ThreadRestoreStep $step, Thread $thread): bool
    {
        if (filled($thread->local_repo_path)) {
            $path = (string) $thread->local_repo_path;
            $workspace = (string) config('yak.sandbox.workspace_path', '/workspace');

            $this->mustRun($step, $thread, ['sh', '-c', 'mkdir -p "$(dirname "$1")" && ln -sfn "$2" "$1"', 'sh', $path, $workspace]);
        }

        $steps = $thread->restoreSteps()->get()->keyBy(fn (ThreadRestoreStep $row): string => $row->step->value);
        $refreshOutput = (string) ($steps[RestoreStepName::RunRefreshTriggers->value]->output ?? '');
        preg_match_all('/^\$ (.+)$/m', $refreshOutput, $matches);
        $setupRan = ($steps[RestoreStepName::RunSetupScript->value]->status ?? null) === RestoreStepStatus::Succeeded;

        $this->note->write($thread, $this->note->sectionsForRestore($thread, $matches[1], $setupRan, $thread->wip_commit_sha, DatasetAvailability::forThread($thread)));

        return true;
    }

    /** Git identity and credentials come from the task sandbox helpers, which only know real containers. */
    private function configureGit(Thread $thread): void
    {
        if (! $this->sandboxes instanceof IncusThreadSandboxManager) {
            return;
        }

        $incus = app(IncusSandboxManager::class);
        $container = $this->sandboxes->containerName($thread);
        $incus->configureGitIdentity($container);
        $incus->injectGitCredentials($container);
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    private function run(ThreadRestoreStep $step, Thread $thread, array $command, array $env = []): ProcessResult
    {
        $result = $this->sandboxes->exec($thread, $command, env: $env);
        $this->append($step, '$ ' . implode(' ', $command) . "\n" . $result->output() . $result->errorOutput() . "\n");

        return $result;
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    private function mustRun(ThreadRestoreStep $step, Thread $thread, array $command, array $env = []): void
    {
        $result = $this->run($step, $thread, $command, $env);

        if ($result->failed()) {
            throw new RuntimeException("{$command[0]} failed (exit {$result->exitCode()})");
        }
    }

    private function append(ThreadRestoreStep $step, string $text): void
    {
        $step->output = mb_strcut($this->scrub(($step->output ?? '') . $text), 0, self::OUTPUT_LIMIT);
    }

    /** Removes build credential values and URL userinfo before text is stored. */
    private function scrub(string $text): string
    {
        foreach ($this->secrets as $secret) {
            $text = str_replace($secret, '[redacted]', $text);
        }

        return (string) preg_replace('#(://)[^/@\s]+@#', '$1[redacted]@', $text);
    }
}
