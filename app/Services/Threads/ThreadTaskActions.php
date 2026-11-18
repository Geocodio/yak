<?php

namespace App\Services\Threads;

use App\Actions\RequestReReview;
use App\DataTransferObjects\ReReviewOutcome;
use App\DataTransferObjects\TaskActionOutcome;
use App\Enums\TaskStatus;
use App\Exceptions\ThreadActionRefused;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\RepoClarificationResolver;
use App\Services\Tasks\TaskActions;

/**
 * The task actions of a thread, for the web page and the app alike. Each method
 * finds the task the action belongs to, refuses when the thread cannot take it
 * and runs the task code. The caller turns the result or the refusal into its
 * own answer. An action with a body has a guard method that runs before the
 * body is validated, so a closed thread answers the same on every client.
 */
final class ThreadTaskActions
{
    /** @var array<string, list<string>> */
    public const array MOVE_RULES = ['repo' => ['required', 'string', 'max:255', 'exists:repositories,slug']];

    /** @var array<string, list<string>> */
    public const array REPOSITORY_ANSWER_RULES = ['repo' => ['required', 'string', 'max:255']];

    public function __construct(
        private readonly TaskActions $actions,
        private readonly ThreadTurns $turns,
        private readonly RequestReReview $requestReReview,
    ) {}

    public function retry(Thread $thread, bool $isTimeBounded = false): TaskActionOutcome
    {
        $isClosedLegacy = $thread->is_legacy && ! $thread->isOpen();
        $head = $isClosedLegacy ? null : ThreadTasks::head($thread);

        if ($head === null) {
            throw self::nothingTo('retry');
        }

        $this->requireOpenWithRepository($thread);

        return $this->actions->retry($head, $isTimeBounded);
    }

    public function setUpRepositoryAndContinue(Thread $thread, User $user): TaskActionOutcome
    {
        $head = ThreadTasks::head($thread) ?? throw self::nothingTo('retry');

        $this->requireOpenWithRepository($thread);

        return $this->actions->setUpRepositoryAndContinue($head, $user);
    }

    public function cancel(Thread $thread, User $user): TaskActionOutcome
    {
        $head = ThreadTasks::head($thread) ?? throw self::nothingTo('cancel');

        $this->requireOpen($thread);
        $this->turns->stop($thread);

        return $this->actions->cancel($head, $user);
    }

    public function guardMove(Thread $thread): YakTask
    {
        $head = ThreadTasks::head($thread) ?? throw self::nothingTo('move');
        $this->requireOpen($thread);

        return $head;
    }

    /**
     * @return array{outcome: TaskActionOutcome, thread: Thread} the thread the task is on after the move
     */
    public function move(YakTask $head, Thread $thread, string $repo): array
    {
        $outcome = $this->actions->reroute($head, $repo);

        return ['outcome' => $outcome, 'thread' => $head->refresh()->thread ?? $thread];
    }

    public function requestReview(Thread $thread): ReReviewOutcome
    {
        $task = ThreadTasks::pullRequestTask($thread);

        if ($task === null || blank($task->pr_url)) {
            throw self::nothingTo('review');
        }

        $this->requireOpenWithRepository($thread);

        return ($this->requestReReview)($task);
    }

    public function retryRender(Thread $thread): TaskActionOutcome
    {
        $task = ThreadTasks::pullRequestTask($thread) ?? throw self::nothingTo('render');

        $this->requireOpenWithRepository($thread);

        return $this->actions->retryRender($task);
    }

    public function guardRepositoryAnswer(Thread $thread): YakTask
    {
        $head = ThreadTasks::head($thread) ?? throw self::nothingTo('answer');
        $this->requireOpen($thread);

        return $head;
    }

    /**
     * @param  bool  $mayPickAnyActive  the web card's "Another repository" option: any active repository, not only the offered ones
     */
    public function answerRepository(Thread $thread, YakTask $head, string $repo, bool $mayPickAnyActive = false): void
    {
        if ($thread->hasRepository()) {
            throw ThreadActionRefused::because(ThreadActionRefused::REPOSITORY_ALREADY_PICKED, 'This thread already has a repository.');
        }

        if ($head->status === TaskStatus::Cancelled) {
            throw ThreadActionRefused::because(ThreadActionRefused::REPOSITORY_NOT_OFFERED, 'This request was cancelled.');
        }

        if (! $mayPickAnyActive && ! in_array($repo, RepoClarificationResolver::offeredOptions($head), true)) {
            throw ThreadActionRefused::because(ThreadActionRefused::REPOSITORY_NOT_OFFERED, 'Pick one of the repositories Yak offered.');
        }

        if (! RepoClarificationResolver::start($head, $repo) || ! $thread->fresh()?->hasRepository()) {
            throw ThreadActionRefused::because(ThreadActionRefused::REPOSITORY_NOT_OFFERED, 'Yak could not start in that repository. Pick another one.');
        }
    }

    public static function nothingTo(string $verb): ThreadActionRefused
    {
        return new ThreadActionRefused("nothing_to_{$verb}", "This thread has nothing to {$verb}.", [], 422);
    }

    private function requireOpen(Thread $thread): void
    {
        if (! $thread->isOpen()) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, 'This thread is closed.');
        }
    }

    private function requireOpenWithRepository(Thread $thread): void
    {
        $this->requireOpen($thread);

        if (! $thread->hasRepository()) {
            throw ThreadActionRefused::because(ThreadActionRefused::NO_REPOSITORY, ThreadActionRefused::NO_REPOSITORY_SENTENCE);
        }
    }
}
