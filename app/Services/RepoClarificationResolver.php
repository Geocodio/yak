<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\ResearchYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\SendNotificationJob;
use App\Models\Repository;
use App\Models\YakTask;

/**
 * Resolves a repo-clarification reply into a concrete repository and
 * kicks off the agent job. Shared by the Slack thread-reply, Slack
 * interactive button, and Yak web UI entry points so each produces
 * the same behaviour when the initial router couldn't pin down a repo.
 */
class RepoClarificationResolver
{
    /**
     * A task is awaiting an initial repo pick when its repo never got
     * resolved and no agent session has started. In that state, any
     * reply (typed or button) is a repo choice — not a mid-run
     * clarification — so it must resolve the repo and dispatch the
     * agent from scratch rather than resuming a session.
     */
    public static function awaitingRepoChoice(YakTask $task): bool
    {
        return $task->repo === 'unknown' && $task->session_id === null;
    }

    /**
     * Match the reply text against the task's offered repo options,
     * update the task with the resolved repo, and dispatch the agent
     * job. Re-prompts via notification when the reply can't be matched.
     */
    public static function resolve(YakTask $task, string $replyText): void
    {
        /** @var list<string> $options */
        $options = $task->clarification_options ?? [];
        $replyNormalized = (string) str($replyText)->lower()->trim()->replaceMatches('/[\s\-_]+/', '-');

        // Three-pass match. The earlier passes guard against the
        // substring trap where "geocodio/laracondb" would otherwise
        // match the shorter "geocodio" repo via str_contains. Order:
        //   1. Exact full-slug match  ("geocodio/laracondb")
        //   2. Exact name-only match  ("laracondb")
        //   3. Substring fallback, longest slug first, so that
        //      "the geocodio website is broken" still resolves to
        //      "geocodio-website" and not "geocodio".
        $normalize = fn (string $s): string => (string) str($s)
            ->lower()
            ->replaceMatches('/[\s\-_]+/', '-');

        $matchedSlug = collect($options)->first(
            fn (string $slug) => $normalize($slug) === $replyNormalized,
        );

        if ($matchedSlug === null) {
            $matchedSlug = collect($options)->first(
                fn (string $slug) => $normalize((string) str($slug)->afterLast('/')) === $replyNormalized,
            );
        }

        if ($matchedSlug === null) {
            $sorted = collect($options)
                ->sortByDesc(fn (string $slug) => mb_strlen($slug))
                ->values();

            $matchedSlug = $sorted->first(function (string $slug) use ($replyNormalized, $normalize) {
                $fullNorm = $normalize($slug);
                $nameNorm = $normalize((string) str($slug)->afterLast('/'));

                return str_contains($replyNormalized, $fullNorm)
                    || str_contains($replyNormalized, $nameNorm);
            });
        }

        if ($matchedSlug === null) {
            TaskLogger::warning($task, 'Could not match repo from reply', ['reply' => $replyText, 'options' => $options]);
            SendNotificationJob::dispatch($task, NotificationType::Clarification, "I didn't recognise that repo.");

            return;
        }

        $repository = Repository::where('slug', $matchedSlug)->where('is_active', true)->first();

        if ($repository === null) {
            TaskLogger::error($task, "Matched repo slug '{$matchedSlug}' not found or inactive");

            return;
        }

        /** @var TaskMode $mode */
        $mode = $task->mode;

        $task->update([
            'repo' => $repository->slug,
            'status' => TaskStatus::Pending,
            'clarification_options' => null,
            'clarification_expires_at' => null,
        ]);

        TaskLogger::info($task, "Repo resolved to {$repository->slug}");

        if ($mode === TaskMode::Research) {
            ResearchYakJob::dispatch($task);

            return;
        }

        RunYakJob::dispatch($task);
    }
}
