<?php

namespace App\Console\Commands;

use App\Actions\RecordPullRequestOutcome;
use App\Channels\GitHub\AppService;
use App\Enums\TaskMode;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ask GitHub about every Yak-opened PR that is still recorded as open.
 * A missed `pull_request.closed` webhook otherwise leaves a task looking
 * open forever, which skews merge rate and blocks follow-ups. While the
 * PR is open the command also refreshes the count of commits humans
 * pushed to the branch, the clearest "first pass wasn't enough" signal.
 */
#[Signature('yak:reconcile-pr-state {--limit=200 : Max PRs to check per run} {--min-age-minutes=30 : Skip PRs checked more recently than this}')]
#[Description('Backfill merged/closed state and human commit counts for Yak-opened PRs')]
class ReconcilePullRequestStateCommand extends Command
{
    public function handle(AppService $github, RecordPullRequestOutcome $outcome): int
    {
        if (! (bool) config('yak.telemetry.reconcile_pr_state', true)) {
            return self::SUCCESS;
        }

        $installationId = (int) config('yak.channels.github.installation_id');

        if ($installationId === 0) {
            $this->components->info('GitHub App not configured — nothing to reconcile.');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $minAge = (int) $this->option('min-age-minutes');

        // One row per PR: the task that opened it. Review tasks share the
        // human author's PR URL and carry no branch of Yak's own.
        $tasks = YakTask::query()
            ->whereNotNull('pr_url')
            ->whereNotNull('pr_number')
            ->whereNull('pr_merged_at')
            ->whereNull('pr_closed_at')
            ->whereNull('parent_task_id')
            ->where('mode', '!=', TaskMode::Review->value)
            ->where(fn ($query) => $query
                ->whereNull('pr_state_checked_at')
                ->orWhere('pr_state_checked_at', '<', now()->subMinutes($minAge)))
            ->orderBy('pr_state_checked_at')
            ->limit($limit)
            ->get();

        $repos = Repository::query()->whereIn('slug', $tasks->pluck('repo')->unique())->get()->keyBy('slug');
        $botLogin = $github->appBotLogin();
        $yakEmail = (string) config('yak.git_user_email');

        $checked = 0;
        $closed = 0;

        foreach ($tasks as $task) {
            $repository = $repos->get($task->repo);
            if ($repository === null) {
                continue;
            }

            try {
                $pr = $github->getPullRequest($installationId, $repository->github_full_name, (int) $task->pr_number);
                $commits = $github->listPullRequestCommits($installationId, $repository->github_full_name, (int) $task->pr_number);
            } catch (Throwable $e) {
                Log::channel('yak')->warning('PR reconcile: GitHub lookup failed', [
                    'task_id' => $task->id,
                    'pr_url' => $task->pr_url,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $checked++;

            $humanCommits = collect($commits)->filter(function (array $commit) use ($botLogin, $yakEmail): bool {
                $login = (string) ($commit['author']['login'] ?? '');
                $email = (string) ($commit['commit']['author']['email'] ?? '');

                return $login !== $botLogin && $email !== $yakEmail;
            })->count();

            if (($pr['state'] ?? 'open') === 'closed') {
                $outcome->record(
                    (string) $task->pr_url,
                    merged: (bool) ($pr['merged'] ?? $pr['merged_at'] !== null),
                    mergedAt: $pr['merged_at'] ?? null,
                    closedAt: $pr['closed_at'] ?? null,
                    via: 'reconcile',
                    humanCommits: $humanCommits,
                );
                $closed++;

                continue;
            }

            YakTask::where('pr_url', $task->pr_url)->update([
                'human_commits' => $humanCommits,
                'pr_state_checked_at' => now(),
            ]);
        }

        $this->components->info("Checked {$checked} open PR(s); recorded {$closed} newly merged/closed.");

        return self::SUCCESS;
    }
}
