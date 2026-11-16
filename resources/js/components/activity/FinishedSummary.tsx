import { ChevronRight } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { COUNT_ORDER, describeCounts, formatSeconds } from '@/components/tasks/groupActivity';
import type { ActivityCounts } from '@/types/tasks';
import { FailedBadge } from './StepRow';
import { StepStack } from './stepIcons';
import type { StepCategory } from './types';

/**
 * A finished run folded to one line: "Worked for 12m 29s · Ran 14 commands,
 * read 6 files". The totals come from the whole run, so they hold outside the
 * loaded window. Opening it replays the run, grouped as it streamed; the rows
 * mount only once it is opened.
 */
export function FinishedSummary({
    durationMs,
    counts,
    failed,
    retried = false,
    stopped = false,
    children,
}: {
    durationMs: number | null;
    counts: ActivityCounts;
    failed: number;
    /** Every failure was followed by a passing run of the same command. */
    retried?: boolean;
    /** The run ended in failure: "Stopped after" instead of "Worked for". */
    stopped?: boolean;
    children: ReactNode;
}) {
    const [opened, setOpened] = useState(false);
    const categories = COUNT_ORDER.filter((category) => counts[category] > 0) as StepCategory[];
    const lead = durationMs !== null && durationMs > 0 ? `${stopped ? 'Stopped after' : 'Worked for'} ${formatSeconds(Math.round(durationMs / 1000))}` : stopped ? 'Stopped' : 'Worked';
    const description = describeCounts(counts);

    return (
        <details
            className="activity-details group/work rounded-[10px] border border-hair bg-panel"
            onToggle={(event) => event.currentTarget.open && setOpened(true)}
            data-testid="finished-summary"
        >
            <summary className="flex min-w-0 cursor-pointer items-center gap-2 rounded-[10px] px-2.5 py-[9px] text-[13px] text-muted group-open/work:rounded-b-none group-open/work:border-b group-open/work:border-hair hover:bg-panel-2">
                <ChevronRight size={14} className="activity-chevron shrink-0 text-faint transition-transform duration-150" aria-hidden />
                <StepStack categories={categories} />
                <span className="min-w-0 flex-1">
                    <b className="font-semibold text-body">{lead}</b>
                    {description && ` · ${description}`}
                </span>
                {failed > 0 && <FailedBadge>
                        {failed} failed{retried ? ', retried' : ''}
                    </FailedBadge>}
            </summary>
            {opened && <div className="flex flex-col gap-2 px-2 py-2.5">{children}</div>}
        </details>
    );
}
