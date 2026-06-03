<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Jobs\RunFollowUpJob;
use App\Models\YakTask;

class FollowUpTaskFactory
{
    /**
     * Create a chained follow-up task for an open PR and dispatch the runner.
     * Returns null (and dispatches nothing) when the PR is already merged or
     * closed — the caller should post a polite decline.
     */
    public function create(YakTask $parent, string $instructions, string $source): ?YakTask
    {
        $head = $this->chainHead($parent);

        if (! $head->prIsOpen()) {
            return null;
        }

        $followUpNumber = $head->conversation()->count();

        $child = YakTask::create([
            'parent_task_id' => $head->id,
            'source' => $source,
            'repo' => $head->repo,
            'mode' => $head->mode,
            'branch_name' => $head->branch_name,
            'session_id' => $head->session_id,
            'pr_url' => $head->pr_url,
            'pr_number' => $head->pr_number,
            'linear_agent_session_id' => $head->linear_agent_session_id,
            'slack_channel' => $head->slack_channel,
            'slack_thread_ts' => $head->slack_thread_ts,
            'slack_user_id' => $head->slack_user_id,
            'external_url' => $head->external_url,
            'external_id' => $head->external_id . '-followup-' . $followUpNumber,
            'description' => $instructions,
            'status' => TaskStatus::Pending,
        ]);

        TaskLogger::info($child, 'Follow-up task created', ['source' => $source, 'parent_id' => $head->id]);

        RunFollowUpJob::dispatch($child);

        return $child;
    }

    /**
     * The newest task in the conversation, so replies always continue from
     * the latest state of the branch.
     */
    private function chainHead(YakTask $task): YakTask
    {
        return $task->conversation()->last() ?? $task;
    }
}
