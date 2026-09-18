<?php

namespace App\Channels\Linear;

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Models\YakTask;
use Illuminate\Support\Facades\Cache;

/**
 * Builds the checklist Linear shows on a fix task's agent session.
 *
 * Linear replaces the whole plan on every update, so each call returns
 * every step. The furthest active step is cached per task so a stopped
 * task can mark the step it stopped on as canceled.
 */
class SessionPlan
{
    private const WORKING_STEP = 0;

    private const CI_STEP = 1;

    /**
     * @return list<array{content: string, status: 'pending'|'inProgress'|'completed'|'canceled'}>|null
     */
    public static function build(YakTask $task, SessionPlanStage $stage): ?array
    {
        if ($task->mode !== TaskMode::Fix) {
            return null;
        }

        $steps = self::steps($task);
        $cacheKey = "linear-session-plan-step:{$task->id}";

        $statuses = match ($stage) {
            SessionPlanStage::Working => self::progressTo(self::WORKING_STEP),
            SessionPlanStage::AwaitingCi => self::progressTo(self::CI_STEP),
            SessionPlanStage::PullRequestOpened => ['completed', 'completed', 'completed'],
            SessionPlanStage::Answered => ['completed', 'canceled', 'canceled'],
            SessionPlanStage::Stopped => self::stoppedAt((int) Cache::get($cacheKey, self::WORKING_STEP)),
        };

        if ($stage === SessionPlanStage::Working) {
            Cache::put($cacheKey, self::WORKING_STEP, now()->addDays(30));
        } elseif ($stage === SessionPlanStage::AwaitingCi) {
            Cache::put($cacheKey, self::CI_STEP, now()->addDays(30));
        }

        return array_map(
            fn (string $content, string $status): array => ['content' => $content, 'status' => $status],
            $steps,
            $statuses,
        );
    }

    /**
     * @return list<string>
     */
    private static function steps(YakTask $task): array
    {
        $ciStep = $task->status === TaskStatus::Retrying
            ? "Fix CI failures and wait for CI to pass (attempt {$task->attempts})"
            : 'Wait for CI to pass';

        if ($task->parent_task_id !== null) {
            return ['Make the requested follow-up changes', $ciStep, 'Push the changes to the pull request'];
        }

        return ['Explore the codebase and make the change', $ciStep, 'Open a pull request'];
    }

    /**
     * @return list<'pending'|'inProgress'|'completed'>
     */
    private static function progressTo(int $activeStep): array
    {
        return array_map(
            fn (int $step): string => match (true) {
                $step < $activeStep => 'completed',
                $step === $activeStep => 'inProgress',
                default => 'pending',
            },
            [0, 1, 2],
        );
    }

    /**
     * @return list<'completed'|'canceled'>
     */
    private static function stoppedAt(int $stoppedStep): array
    {
        return array_map(
            fn (int $step): string => $step < $stoppedStep ? 'completed' : 'canceled',
            [0, 1, 2],
        );
    }
}
