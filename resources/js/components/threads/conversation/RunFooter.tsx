import { formatSeconds } from '@/lib/formatSeconds';
import type { ThreadMilestone, ThreadRunSummary } from '@/types/threads';
import { MilestoneRow } from './MilestoneRow';
import { RunLogButton } from './RunLogButton';

/** The line after a run's answer: how long it worked, what it reached, and its full log. */
export function RunFooter({ summary, retryNumber, milestones, modelLabels = {}, onOpenLog }: { modelLabels?: Record<string, string>; summary?: Pick<ThreadRunSummary, 'durationSeconds' | 'stepCount' | 'model'>; retryNumber?: number | null; milestones: ThreadMilestone[]; onOpenLog: () => void }) {
    const worked = summary === undefined ? null : `${retryNumber ? `Retry ${retryNumber}: worked` : 'Worked'} for ${formatSeconds(summary.durationSeconds)} · ${summary.stepCount} ${summary.stepCount === 1 ? 'step' : 'steps'}${summary.model ? ` · ${modelLabels[summary.model] ?? summary.model}` : ''}`;

    return (
        <div className="ml-9 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[12px] text-muted" data-testid="run-footer">
            {worked !== null && <span data-testid="run-footer-summary">{worked}</span>}
            <MilestoneRow milestones={milestones} />
            <span className="ml-auto">
                <RunLogButton onOpen={onOpenLog} />
            </span>
        </div>
    );
}
