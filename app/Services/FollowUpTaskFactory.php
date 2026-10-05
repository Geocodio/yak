<?php

namespace App\Services;

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Facades\Telemetry;
use App\Jobs\ResearchFollowUpJob;
use App\Jobs\RunFollowUpJob;
use App\Models\YakTask;
use Illuminate\Support\Facades\DB;

class FollowUpTaskFactory
{
    /**
     * Create a chained follow-up task and dispatch the runner: for an open PR,
     * or for a finished research task (a follow-up question). Returns null
     * (and dispatches nothing) when the PR is already merged or closed and the
     * head is not a finished research task -- the caller should post a polite
     * decline.
     *
     * @param  array<int, string>  $reRequestReviewFrom  GitHub logins to re-request review from once this follow-up succeeds
     * @param  int|null  $summonReviewCommentId  Review comment thread the summary reply goes to, when summoned from an inline comment
     * @param  string|null  $summonQuote  Conversation-tab comment the summary reply quotes, when summoned from one
     * @param  string|null  $slackFollowUpUserId  Slack user who replied in the thread, mentioned alongside the original requester
     */
    public function create(YakTask $parent, string $instructions, string $source, ?string $authorName = null, array $reRequestReviewFrom = [], ?int $summonReviewCommentId = null, ?string $summonQuote = null, ?string $slackFollowUpUserId = null): ?YakTask
    {
        // One conversation() walk gives us both ends of the chain: the root
        // (stable base for external_id) and the head (newest task — its branch
        // /session/PR state is what a follow-up continues from).
        $chain = $parent->conversation();
        $head = $chain->last() ?? $parent;
        $root = $chain->first() ?? $parent;

        if (! $head->acceptsFollowUp()) {
            return null;
        }

        $isResearch = $head->mode === TaskMode::Research;

        $child = DB::transaction(function () use ($head, $root, $isResearch, $instructions, $source, $authorName, $reRequestReviewFrom, $summonReviewCommentId, $summonQuote, $slackFollowUpUserId): YakTask {
            $child = YakTask::create([
                'parent_task_id' => $head->id,
                'source' => $source,
                'repo' => $head->repo,
                'mode' => $head->mode,
                'branch_name' => $isResearch ? null : $head->branch_name,
                'session_id' => $head->session_id,
                'pr_url' => $isResearch ? null : $head->pr_url,
                'pr_number' => $isResearch ? null : $head->pr_number,
                'linear_agent_session_id' => $head->linear_agent_session_id,
                'slack_channel' => $head->slack_channel,
                'slack_thread_ts' => $head->slack_thread_ts,
                'slack_user_id' => $head->slack_user_id,
                'slack_follow_up_user_id' => $slackFollowUpUserId,
                'external_url' => $head->external_url,
                'external_id' => $root->external_id . '-followup',
                'description' => $instructions,
                'author_name' => $authorName,
                'responsible_name' => app(ResponsiblePersonResolver::class)->resolve($head->responsible_name, $authorName, $head->repo),
                'started_by_user_id' => $head->started_by_user_id,
                'responsible_user_id' => $head->responsible_user_id,
                're_request_review_from' => $this->cleanLogins($reRequestReviewFrom),
                'targets_external_pr' => ! $isResearch && $head->targets_external_pr,
                'summon_review_comment_id' => $summonReviewCommentId,
                'summon_quote' => $summonQuote,
                'status' => TaskStatus::Pending,
            ]);

            // Derive a collision-free, non-compounding external_id from the
            // chain root plus this row's own primary key — avoids the
            // count-based race and prevents '-followup-N-followup-M' growth.
            $child->update(['external_id' => $root->external_id . '-followup-' . $child->id]);

            return $child;
        });

        TaskLogger::info($child, 'Follow-up task created', ['source' => $source, 'parent_id' => $head->id]);

        // Round N of the conversation: how often people go back and forth
        // before a PR lands is the Analytics page's one-shot rate.
        Telemetry::feature('follow_up', [
            'round' => $chain->count(),
            'root_task_id' => $root->id,
            'chars' => mb_strlen($instructions),
        ], task: $child);

        if ($isResearch) {
            app(AgentJobDispatcher::class)->dispatch($child, ResearchFollowUpJob::class);
        } else {
            RunFollowUpJob::dispatch($child)->afterCommit();
        }

        return $child;
    }

    /**
     * Distinct, non-empty logins, or null when nothing remains -- an empty
     * login flowing through would ask GitHub to re-request review from "".
     *
     * @param  array<int, string>  $logins
     * @return array<int, string>|null
     */
    private function cleanLogins(array $logins): ?array
    {
        $cleaned = array_values(array_unique(array_filter($logins, fn (string $login): bool => $login !== '')));

        return $cleaned !== [] ? $cleaned : null;
    }
}
