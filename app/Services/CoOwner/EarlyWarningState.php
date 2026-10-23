<?php

namespace App\Services\CoOwner;

use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\DB;

/**
 * The only reader and writer of tasks.early_warning. The state lives on the
 * conversation root, and every write happens under a row lock on it.
 */
class EarlyWarningState
{
    /** @param list<array{name: string, risk: string}> $areas */
    public function start(YakTask $task, array $areas): void
    {
        $this->update($task, fn (YakTask $root): array => [
            'areas' => $areas,
            'state' => 'shown',
            'slackChannel' => null,
            'slackTs' => null,
            'taggedAt' => null,
        ]);
    }

    public function setSlackMessage(YakTask $task, string $channel, string $timestamp): void
    {
        $this->update($task, fn (YakTask $root): ?array => $root->early_warning === null ? null : [
            ...$root->early_warning,
            'slackChannel' => $channel,
            'slackTs' => $timestamp,
        ]);
    }

    public function tagged(YakTask $task, User $coOwner): void
    {
        $this->update($task, fn (YakTask $root): ?array => $root->early_warning === null ? null : [
            ...$root->early_warning,
            'state' => 'tagged',
            'taggedAt' => now()->toIso8601String(),
        ], $coOwner->id);
    }

    public function declined(YakTask $task): void
    {
        $this->update($task, fn (YakTask $root): ?array => $root->early_warning === null ? null : [
            ...$root->early_warning,
            'state' => 'declined',
        ]);
    }

    /**
     * @param  callable(YakTask): (array<string, mixed>|null)  $next  the new json, or null to leave the row alone
     */
    private function update(YakTask $task, callable $next, ?int $taggedCoOwnerUserId = null): void
    {
        DB::transaction(function () use ($task, $next, $taggedCoOwnerUserId): void {
            $locked = YakTask::whereKey($task->rootTask()->id)->lockForUpdate()->firstOrFail();
            $warning = $next($locked);

            if ($warning === null) {
                return;
            }

            $locked->early_warning = $warning;
            if ($taggedCoOwnerUserId !== null) {
                $locked->tagged_co_owner_user_id = $taggedCoOwnerUserId;
            }
            $locked->save();
        });
    }
}
