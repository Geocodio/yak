import { Skeleton } from '@geocodio/console-ui';
import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { ThreadPageData } from '@/types/threads';
import { DiffSkeleton } from './DiffSkeleton';
import { useDelayedFlag } from './useDelayedFlag';

const CARDS = [
    { width: '58%', additions: 3, deletions: 1 },
    { width: '44%', additions: 2, deletions: 2 },
    { width: '66%', additions: 4, deletions: 0 },
];
const TREE_ROWS = ['62%', '78%', '54%', '70%', '48%'];

/**
 * The Changed view's own layout while its first diff loads: the header with the
 * thread's real line totals at once, then after a short delay the file tree and
 * diff cards as placeholders, so nothing moves when the files arrive.
 */
export function ChangesSkeleton({ isNarrow, actions }: { isNarrow: boolean; actions?: ReactNode }) {
    const { diffTotals } = usePage<ThreadPageData & Record<string, unknown>>().props;
    const isBodyShown = useDelayedFlag(true);

    return (
        <div className="flex min-h-0 flex-1 flex-col" data-testid="changes-skeleton" aria-busy="true" aria-label="Loading changes">
            <div className="flex min-h-[45px] shrink-0 items-center gap-3 border-b border-hair px-3 py-2">
                <Skeleton className="h-[9px] w-[92px] rounded-[4px]" />
                {diffTotals !== null && (
                    <span className="font-mono text-[12px] font-medium tabular-nums">
                        <span className="text-ok">+{diffTotals.additions}</span> <span className="text-fail">-{diffTotals.deletions}</span>
                    </span>
                )}
                <span className="grow" />
                {actions}
            </div>
            {isBodyShown && (
                <div className="flex min-h-0 flex-1">
                    {!isNarrow && (
                        <div className="flex w-[250px] shrink-0 flex-col gap-2.5 border-r border-hair bg-app p-3">
                            <Skeleton className="h-[30px] rounded-[6px]" />
                            <Skeleton className="h-1 rounded-full" />
                            {TREE_ROWS.map((width, index) => (
                                <div key={index} className="flex items-center gap-2 py-1">
                                    <Skeleton className="size-3.5 rounded-[3px]" />
                                    <Skeleton className="h-2 rounded-[4px]" style={{ width }} />
                                </div>
                            ))}
                        </div>
                    )}
                    <div className="flex min-w-0 flex-1 flex-col gap-3.5 bg-app p-3.5 max-lg:p-2.5">
                        {CARDS.map((card, index) => (
                            <div key={index} className="overflow-hidden rounded-[10px] border border-hair bg-panel">
                                <div className="flex items-center gap-2 border-b border-hair bg-app px-3 py-2.5">
                                    <Skeleton className="h-[9px] rounded-[4px]" style={{ width: card.width }} />
                                    <span className="grow" />
                                    <Skeleton className="h-[9px] w-11 rounded-[4px]" />
                                </div>
                                <DiffSkeleton additions={card.additions} deletions={card.deletions} />
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
