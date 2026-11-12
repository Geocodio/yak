<?php

namespace App\Services\Stacks;

use App\Events\StackThresholdReached;
use App\Models\Thread;
use App\Services\Push\StackUsagePush;
use Throwable;

/**
 * Tells the owner when a stack crosses a usage mark: every N turns, every N
 * hours of activity, every N children spawned. Each mark alerts once and
 * nothing is paused.
 */
class StackUsageAlerts
{
    public function __construct(private readonly StackUsage $usage, private readonly StackNotifier $notifier, private readonly StackUsagePush $push) {}

    /**
     * @return list<string> the notices sent
     */
    public function check(Thread $root): array
    {
        $usage = $this->usage->for($root);
        $sent = [];

        $marks = [
            ['turns', 'stack_alert_turn_mark', intdiv($usage['turnCount'], max(1, (int) config('yak.threads.stack_alert_turns'))), "its stack has run {$usage['turnCount']} turns"],
            ['hours', 'stack_alert_hours_mark', intdiv((int) floor($usage['activeHours']), max(1, (int) config('yak.threads.stack_alert_hours'))), "its stack has been active for {$usage['activeHours']} hours"],
            ['children', 'stack_alert_child_mark', intdiv($usage['childCount'], max(1, (int) config('yak.threads.stack_alert_children'))), "its stack has spawned {$usage['childCount']} sub-threads"],
        ];

        foreach ($marks as [$threshold, $column, $mark, $subject]) {
            if ($mark <= $root->{$column}) {
                continue;
            }

            $root->forceFill([$column => $mark])->save();
            $notice = $subject . '. Nothing was paused.';
            $this->notifier->notifyOwner($root, $notice);

            try {
                $this->push->send($root, $notice);
            } catch (Throwable $e) {
                report($e);
            }

            StackThresholdReached::dispatch($root, $threshold, $mark);
            $sent[] = $subject;
        }

        return $sent;
    }
}
