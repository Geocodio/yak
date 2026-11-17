import { cn } from '@geocodio/console-ui';
import { CircleAlert, CircleCheck, CircleX } from 'lucide-react';
import type { ThreadMilestone } from '@/types/threads';

const TONE: Record<ThreadMilestone['tone'], { icon: typeof CircleCheck; className: string }> = {
    ok: { icon: CircleCheck, className: 'text-ok' },
    warn: { icon: CircleAlert, className: 'text-warn' },
    bad: { icon: CircleX, className: 'text-fail' },
};

const PILL = 'inline-flex items-center gap-[5px] rounded-pill bg-panel-2 py-[3px] pr-[9px] pl-1.5 text-[12px] text-muted';

function showWalkthrough(): void {
    document.querySelector('[data-testid="thread-walkthrough"]')?.scrollIntoView({ block: 'center', behavior: 'smooth' });
}

/** What a run reached, as pills after its answer: a push, CI, the pull request, a walkthrough, a report. It wraps on a narrow screen. */
export function MilestoneRow({ milestones }: { milestones: ThreadMilestone[] }) {
    if (milestones.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center gap-1.5" data-testid="milestone-row">
            {milestones.map((milestone) => {
                const { icon: Icon, className } = TONE[milestone.tone];
                const content = (
                    <>
                        <Icon size={13} className={cn('shrink-0', className)} aria-hidden />
                        {milestone.label}
                    </>
                );
                const testId = `milestone-${milestone.key}`;

                if (milestone.url !== null) {
                    return (
                        <a key={milestone.key} href={milestone.url} target="_blank" rel="noopener noreferrer" className={cn(PILL, 'no-underline hover:bg-hair hover:text-body')} data-testid={testId}>
                            {content}
                        </a>
                    );
                }

                if (milestone.key === 'walkthrough_ready') {
                    return (
                        <button key={milestone.key} type="button" onClick={showWalkthrough} className={cn(PILL, 'cursor-pointer hover:bg-hair hover:text-body')} data-testid={testId}>
                            {content}
                        </button>
                    );
                }

                return (
                    <span key={milestone.key} className={PILL} data-testid={testId}>
                        {content}
                    </span>
                );
            })}
        </div>
    );
}
