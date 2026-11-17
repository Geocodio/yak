<?php

namespace App\Services\Tasks;

use App\Enums\TaskStatus;
use App\Exceptions\ThreadActionRefused;
use App\Models\PendingSteeringMessage;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\RepoClarificationResolver;
use Closure;
use Throwable;

/**
 * Answers a reply on a task that has stopped without a PR: a reply to a
 * failed or expired task retries it with the reply as a note.
 */
class StoppedTaskReplies
{
    private const CANNOT_RETRY = "Yak can't retry this one. Start a new request and it'll pick it up.";

    private const TRY_AGAIN_LATER = "Yak couldn't restart this right now. Reply again in a moment.";

    public function __construct(private readonly TaskActions $taskActions) {}

    /**
     * @param  Closure(PendingSteeringMessage): mixed|null  $afterNoteQueued  runs once the reply is queued as a note, so a caller can attach files to it
     * @param  int|null  $authorUserId  the Yak user who wrote the reply, stored on the note
     * @return string the reply to post back to the starter
     */
    public function handle(YakTask $task, string $message, string $source, ?string $authorName = null, ?Closure $afterNoteQueued = null, ?int $authorUserId = null): string
    {
        if ($task->status === TaskStatus::Cancelled) {
            return $source === 'slack'
                ? 'This was stopped. Start a new request to try again.'
                : 'This was stopped. Delegate it to Yak again to start over.';
        }

        /** @var list<string> $activeSlugs */
        $activeSlugs = Repository::where('is_active', true)->pluck('slug')->all();

        if (! in_array($task->repo, $activeSlugs, true)) {
            return $this->chooseRepository($task, $message, $activeSlugs);
        }

        $isAlreadyActive = in_array($task->status, [TaskStatus::Pending, TaskStatus::Running, TaskStatus::Retrying, TaskStatus::AwaitingCi], true);

        $note = PendingSteeringMessage::queueFor($task, $message, $source, authorName: $authorName, authorUserId: $authorUserId);

        if ($afterNoteQueued !== null) {
            $afterNoteQueued($note);
        }

        try {
            $this->taskActions->retry($task);
        } catch (ThreadActionRefused) {
            $note->delete();

            return self::CANNOT_RETRY;
        } catch (Throwable $exception) {
            $note->delete();
            report($exception);

            return self::TRY_AGAIN_LATER;
        }

        return $isAlreadyActive ? 'Yak is already on it. Your note is included.' : 'Trying again with your note.';
    }

    /**
     * @param  list<string>  $activeSlugs
     */
    private function chooseRepository(YakTask $task, string $message, array $activeSlugs): string
    {
        $slug = RepoClarificationResolver::matchSlug($activeSlugs, $message);

        if ($slug === null) {
            return 'Which repository? One of: ' . implode(', ', $activeSlugs) . '.';
        }

        try {
            $this->taskActions->reroute($task, $slug);
        } catch (ThreadActionRefused) {
            return self::CANNOT_RETRY;
        } catch (Throwable $exception) {
            report($exception);

            return self::TRY_AGAIN_LATER;
        }

        return "Working in {$slug} now.";
    }
}
