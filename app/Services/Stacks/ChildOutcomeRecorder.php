<?php

namespace App\Services\Stacks;

use App\Enums\StackThreadMessageKind;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadKind;
use App\Enums\TurnState;
use App\Models\StackThreadMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Services\IncusSandboxManager;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\WipPreserver;
use App\Support\SecretRedactor;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns the end of a child's turn into a message for its parent: the result
 * is queued for batching, a failure is delivered at once. The child's branch
 * is pushed and its sandbox destroyed before an implement or mockup result
 * is queued; the agent never pushes.
 */
class ChildOutcomeRecorder
{
    private const SUMMARY_LIMIT = 4000;

    public function __construct(
        private readonly ThreadSandbox $sandbox,
        private readonly SandboxManager $sandboxes,
        private readonly WipPreserver $wip,
        private readonly StackResultBatcher $batcher,
        private readonly StackNotifier $notifier,
    ) {}

    public function recordFinished(Thread $child, TaskRun $run): void
    {
        if ($this->isIgnored($child, $run)) {
            return;
        }

        $summary = $this->summaryOf($run);
        $child->forceFill(['result_summary' => $summary])->save();
        $text = $summary;

        if (in_array($child->kind, [ThreadKind::Implement, ThreadKind::Mockup], true)) {
            $artifacts = $child->kind === ThreadKind::Mockup ? $this->artifactNames($child) : [];

            if (! $this->pushWork($child)) {
                $this->queueFailure($child, "Sub-thread {$child->name} finished, but its work could not be pushed to {$child->branch}. Its sandbox is kept. Summary: {$summary}");

                return;
            }

            $this->sandbox->destroy($child);

            if ($artifacts !== []) {
                $text .= "\n\nArtifacts: " . implode(', ', $artifacts);
            }
        }

        if ($this->isRepoChild($child)) {
            $text .= "\n\n" . $this->pullRequestLine($child);
        }

        $this->queue($child, StackThreadMessageKind::Result, $text, now(), $this->isRepoChild($child) ? null : $child->branch);

        $parent = Thread::query()->withoutGlobalScopes()->find($child->parent_thread_id);

        if ($parent !== null) {
            $this->tryDeliver(fn () => $this->batcher->deliverFor($parent));
        }
    }

    public function recordFailed(Thread $child, TaskRun $run): void
    {
        if ($this->isIgnored($child, $run)) {
            return;
        }

        $child->refresh();
        $usageLimit = $child->blocked_reason === ThreadBlockReason::UsageLimit;
        $what = match (true) {
            $usageLimit => 'hit its Claude usage limit',
            $run->turn_state === TurnState::StoppedByGuard => 'was stopped by the runaway guard',
            default => 'failed before finishing; its runner stopped or crashed',
        };
        $last = $child->result_summary ?? $child->current_step ?? 'nothing yet';
        $branch = $child->branch === null ? '' : " Its branch is {$child->branch}.";

        $this->queueFailure($child, "Sub-thread {$child->name} {$what}. Last progress: {$last}.{$branch} Nothing retries on its own.");

        if ($usageLimit) {
            $this->notifier->notifyOwner($child->rootThread(), "sub-thread {$child->name} hit its usage limit");
        }
    }

    /** A child in another repo opens its own pull request; its branch is never merged into the parent. */
    private function isRepoChild(Thread $child): bool
    {
        $parent = Thread::query()->withoutGlobalScopes()->find($child->parent_thread_id);

        return $parent !== null && $parent->repo !== $child->repo;
    }

    private function pullRequestLine(Thread $child): string
    {
        $url = $child->tasks()->whereNotNull('pr_url')->latest('id')->value('pr_url')
            ?? $child->primaryRepository?->pull_request_url;

        return $url === null
            ? "Its work is on branch {$child->branch} in {$child->repo}, which gets its own pull request there. Do not merge it into your branch."
            : "Its own pull request in {$child->repo}: {$url}. Do not merge it into your branch.";
    }

    private function isIgnored(Thread $child, TaskRun $run): bool
    {
        return ! $child->isChild()
            || $child->taken_over_at !== null
            || $run->turn_state === TurnState::Interrupted
            || $child->rootThread()->stack_stopped_at !== null;
    }

    private function queueFailure(Thread $child, string $text): void
    {
        $message = $this->queue($child, StackThreadMessageKind::Failure, $text, null, $child->branch);

        $this->tryDeliver(fn () => $this->batcher->deliverImmediate($message));
    }

    private function queue(Thread $child, StackThreadMessageKind $kind, string $text, mixed $queuedAt, ?string $branch): StackThreadMessage
    {
        return StackThreadMessage::query()->create([
            'root_thread_id' => $child->rootThread()->id,
            'from_thread_id' => $child->id,
            'to_thread_id' => $child->parent_thread_id,
            'kind' => $kind,
            'text' => $text,
            'branch' => $branch,
            'queued_at' => $queuedAt,
        ]);
    }

    private function summaryOf(TaskRun $run): string
    {
        $text = is_array($run->last_result_event) ? (string) ($run->last_result_event['result'] ?? '') : '';
        $text = trim($text) === '' ? 'The sub-thread finished without a written summary.' : $text;

        return mb_substr(SecretRedactor::redact($text), 0, self::SUMMARY_LIMIT);
    }

    private function pushWork(Thread $child): bool
    {
        try {
            if (! $this->sandbox->ensureAwake($child)->ready) {
                return false;
            }

            $this->wip->preserve($child);

            return true;
        } catch (Throwable $exception) {
            Log::warning('Pushing a sub-thread branch failed', ['thread' => $child->id, 'error' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function artifactNames(Thread $child): array
    {
        try {
            $listing = $this->sandboxes->exec($child, ['ls', '-1', IncusSandboxManager::workspacePath() . '/.yak-artifacts']);

            return $listing->successful() ? array_values(array_filter(array_map('trim', explode("\n", $listing->output())))) : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  callable(): mixed  $delivery
     */
    private function tryDeliver(callable $delivery): void
    {
        try {
            $delivery();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
