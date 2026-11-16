import { OpenKeysProvider } from '@/components/activity/openKeys';
import { ActiveGroup, StepGroup } from '@/components/activity/StepGroup';
import { formatStepDuration } from '@/components/tasks/groupActivity';
import type { ConversationItem } from './conversationItem';

/**
 * A run of tool calls in the thread, drawn by the same step rows and groups
 * as a task's stream: folded to one line when finished, the active group
 * with its current step while the latest call is still running.
 */
export function ToolSteps({ item, live }: { item: ConversationItem; live: boolean }) {
    const steps = item.steps ?? [];

    return (
        <div className="ml-9" data-testid={live ? 'thread-working-block' : 'thread-tool-activity'}>
            <OpenKeysProvider>
                {live ? (
                    <ActiveGroup groupKey={item.key} steps={steps} currentSince={null} />
                ) : (
                    <StepGroup groupKey={item.key} steps={steps} duration={item.durationMs !== undefined ? formatStepDuration(item.durationMs) : null} />
                )}
            </OpenKeysProvider>
        </div>
    );
}
