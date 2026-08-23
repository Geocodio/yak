<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Jobs\RunFollowUpJob;
use App\Models\YakTask;
use Illuminate\Support\Facades\DB;

class FollowUpTaskFactory
{
    /**
     * Create a chained follow-up task for an open PR and dispatch the runner.
     * Returns null (and dispatches nothing) when the PR is already merged or
     * closed — the caller should post a polite decline.
     */
    public function create(YakTask $parent, string $instructions, string $source, ?string $authorName = null): ?YakTask
    {
        // One conversation() walk gives us both ends of the chain: the root
        // (stable base for external_id) and the head (newest task — its branch
        // /session/PR state is what a follow-up continues from).
        $chain = $parent->conversation();
        $head = $chain->last() ?? $parent;
        $root = $chain->first() ?? $parent;

        if (! $head->prIsOpen()) {
            return null;
        }

        $child = DB::transaction(function () use ($head, $root, $instructions, $source, $authorName): YakTask {
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
                'external_id' => $root->external_id . '-followup',
                'description' => $instructions,
                'author_name' => $authorName,
                'status' => TaskStatus::Pending,
            ]);

            // Derive a collision-free, non-compounding external_id from the
            // chain root plus this row's own primary key — avoids the
            // count-based race and prevents '-followup-N-followup-M' growth.
            $child->update(['external_id' => $root->external_id . '-followup-' . $child->id]);

            return $child;
        });

        TaskLogger::info($child, 'Follow-up task created', ['source' => $source, 'parent_id' => $head->id]);

        RunFollowUpJob::dispatch($child)->afterCommit();

        return $child;
    }
}
