<?php

namespace App\Http\Controllers\Tasks;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Channels\Linear\NotificationDriver as LinearNotificationDriver;
use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\RerouteTaskRequest;
use App\Jobs\RenderVideoJob;
use App\Jobs\ResearchYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\RunYakReviewJob;
use App\Jobs\SendNotificationJob;
use App\Jobs\SetupYakJob;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\AgentJobDispatcher;
use App\Services\IncusSandboxManager;
use App\Services\TaskLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaskActionController extends Controller
{
    public function retry(YakTask $task): RedirectResponse
    {
        if (! in_array($task->status, [TaskStatus::Failed, TaskStatus::Expired], true)) {
            return redirect()->route('tasks.show', $task)->with('error', 'This task cannot be retried right now.');
        }

        $task->update([
            'status' => TaskStatus::Pending,
            'error_log' => null,
            'result_summary' => null,
            'cost_usd' => 0,
            'duration_ms' => 0,
            'num_turns' => 0,
            'started_at' => null,
            'completed_at' => null,
        ]);

        /** @var TaskMode $mode */
        $mode = $task->mode;

        $jobClass = match ($mode) {
            TaskMode::Setup => SetupYakJob::class,
            TaskMode::Research => ResearchYakJob::class,
            TaskMode::Review => RunYakReviewJob::class,
            default => RunYakJob::class,
        };

        app(AgentJobDispatcher::class)->dispatch($task, $jobClass);

        return redirect()->route('tasks.show', $task)->with('success', 'Task re-queued.');
    }

    public function cancel(YakTask $task): RedirectResponse
    {
        $cancellable = in_array($task->status, [
            TaskStatus::Pending,
            TaskStatus::Running,
            TaskStatus::AwaitingClarification,
            TaskStatus::AwaitingCi,
            TaskStatus::Retrying,
        ], true);

        if (! $cancellable) {
            return redirect()->route('tasks.show', $task)->with('error', 'This task cannot be cancelled right now.');
        }

        TaskLogger::info($task, 'Task cancelled by user');

        $containerName = 'task-' . $task->id;

        try {
            app(IncusSandboxManager::class)->destroy($containerName);
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('Sandbox destroy failed during cancel', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }

        $task->update([
            'status' => TaskStatus::Cancelled,
            'completed_at' => now(),
        ]);

        SendNotificationJob::dispatch(
            $task,
            NotificationType::Expiry,
            'Task cancelled from the dashboard.',
        );

        if ($task->source === 'linear') {
            $cancelledStateId = (string) config('yak.channels.linear.cancelled_state_id');
            if ($cancelledStateId !== '') {
                app(LinearNotificationDriver::class)->setIssueState($task, $cancelledStateId);
            }
        }

        return redirect()->route('tasks.show', $task)->with('success', 'Task cancelled.');
    }

    public function rerunReview(YakTask $task, GitHubAppService $github): RedirectResponse
    {
        if ($task->mode !== TaskMode::Review) {
            return redirect()->route('tasks.show', $task)->with('error', 'This task is not a review.');
        }

        if (in_array($task->status, [TaskStatus::Pending, TaskStatus::Running], true)) {
            return redirect()->route('tasks.show', $task)->with('error', 'A review is already queued for this PR.');
        }

        $installationId = (int) config('yak.channels.github.installation_id');
        $oldContext = json_decode((string) $task->context, true) ?: [];
        $prNumber = $oldContext['pr_number'] ?? null;

        if ($prNumber === null) {
            return redirect()->route('tasks.show', $task)->with('error', 'Cannot determine PR number.');
        }

        $prPayload = $github->getPullRequest($installationId, $task->repo, (int) $prNumber);

        if (! isset($prPayload['head']['sha'])) {
            return redirect()->route('tasks.show', $task)->with('error', 'Failed to fetch PR from GitHub.');
        }

        PrReview::where('yak_task_id', $task->id)->delete();

        DB::table('tasks')->where('id', $task->id)->update([
            'status' => TaskStatus::Pending->value,
            'error_log' => null,
            'result_summary' => null,
            'cost_usd' => 0,
            'duration_ms' => 0,
            'num_turns' => 0,
            'started_at' => null,
            'completed_at' => null,
            'branch_name' => (string) $prPayload['head']['ref'],
            'context' => json_encode([
                'pr_number' => (int) $prPayload['number'],
                'head_sha' => (string) $prPayload['head']['sha'],
                'head_ref' => (string) $prPayload['head']['ref'],
                'base_sha' => (string) $prPayload['base']['sha'],
                'base_ref' => (string) $prPayload['base']['ref'],
                'author' => (string) ($prPayload['user']['login'] ?? ''),
                'title' => (string) ($prPayload['title'] ?? ''),
                'body' => (string) ($prPayload['body'] ?? ''),
                'review_scope' => 'full',
                'incremental_base_sha' => null,
            ]),
            'updated_at' => now(),
        ]);

        $task->refresh();

        app(AgentJobDispatcher::class)->dispatch($task, RunYakReviewJob::class);

        return redirect()->route('tasks.show', $task)->with('success', 'Re-running review for this PR.');
    }

    public function retryRender(YakTask $task): RedirectResponse
    {
        $rawFootage = $task->artifacts()->rawFootage()->latest('id')->first();

        if ($rawFootage === null) {
            return redirect()->route('tasks.show', $task)->with('error', 'Nothing to re-render for this task.');
        }

        RenderVideoJob::dispatch($rawFootage->id);

        return redirect()->route('tasks.show', $task)->with('success', 'Re-rendering the walkthrough.');
    }

    public function reroute(RerouteTaskRequest $request, YakTask $task): RedirectResponse
    {
        $canReroute = ! in_array($task->mode, [TaskMode::Setup, TaskMode::Review], true) && $task->pr_url === null;

        if (! $canReroute) {
            return redirect()->route('tasks.show', $task)->with('error', 'This task cannot be moved to another repo.');
        }

        $slug = $request->validated('repo');

        $newRepo = Repository::where('slug', $slug)->where('is_active', true)->first();

        if ($newRepo === null) {
            return redirect()->route('tasks.show', $task)->with('error', 'Repository not found or inactive.');
        }

        $oldRepo = (string) $task->repo;

        if ($newRepo->slug === $oldRepo) {
            return redirect()->route('tasks.show', $task);
        }

        $inFlight = in_array($task->status, [
            TaskStatus::Running,
            TaskStatus::AwaitingClarification,
            TaskStatus::AwaitingCi,
            TaskStatus::Retrying,
        ], true);

        if ($inFlight) {
            try {
                app(IncusSandboxManager::class)->destroy('task-' . $task->id);
            } catch (\Throwable $e) {
                Log::channel('yak')->warning('Sandbox destroy failed during reroute', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        DB::table('tasks')->where('id', $task->id)->update([
            'repo' => $newRepo->slug,
            'status' => TaskStatus::Pending->value,
            'branch_name' => null,
            'error_log' => null,
            'result_summary' => null,
            'cost_usd' => 0,
            'duration_ms' => 0,
            'num_turns' => 0,
            'started_at' => null,
            'completed_at' => null,
            'updated_at' => now(),
        ]);

        $task->refresh();

        TaskLogger::info($task, "Task rerouted from {$oldRepo} to {$newRepo->slug}");

        /** @var TaskMode $mode */
        $mode = $task->mode;

        $jobClass = match ($mode) {
            TaskMode::Research => ResearchYakJob::class,
            default => RunYakJob::class,
        };

        app(AgentJobDispatcher::class)->dispatch($task, $jobClass);

        SendNotificationJob::dispatch(
            $task,
            NotificationType::Retry,
            "Moved from {$oldRepo} to {$newRepo->slug} — restarting there.",
        );

        return redirect()->route('tasks.show', $task)->with('success', "Task moved to {$newRepo->slug}.");
    }
}
