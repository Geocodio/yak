import type { ThreadMilestone, ThreadRunSummary } from '@/types/threads';
import { MilestoneRow } from './MilestoneRow';
import { RunLogButton } from './RunLogButton';

function duration(seconds: number): string {
    if (seconds < 60) {
        return `${seconds}s`;
    }
    const minutes = Math.round(seconds / 60);

    return minutes < 60 ? `${minutes}m` : `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

/** The line after a run's answer: how long it worked, what it reached, and its full log. */
export function RunFooter({ summary, retryNumber, milestones, onOpenLog }: { summary?: Pick<ThreadRunSummary, 'durationSeconds' | 'stepCount'>; retryNumber?: number | null; milestones: ThreadMilestone[]; onOpenLog: () => void }) {
    const worked = summary === undefined ? null : `${retryNumber ? `Retry ${retryNumber}: worked` : 'Worked'} for ${duration(summary.durationSeconds)} · ${summary.stepCount} ${summary.stepCount === 1 ? 'step' : 'steps'}`;

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
