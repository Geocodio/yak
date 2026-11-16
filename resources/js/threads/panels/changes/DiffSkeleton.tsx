import { Skeleton, cn } from '@geocodio/console-ui';

const WIDTHS = ['42%', '68%', '55%', '74%', '38%', '61%', '49%', '70%'];

/**
 * A diff-shaped placeholder: line rows tinted green and red in the file's real
 * share of added and deleted lines, so the shape of the change shows before its code.
 */
export function DiffSkeleton({ additions, deletions, rows = 6 }: { additions: number | null; deletions: number | null; rows?: number }) {
    const added = additions ?? 0;
    const deleted = deletions ?? 0;
    const addedRows = Math.round((rows - 1) * (added / Math.max(1, added + deleted)));
    const deletedRows = deleted > 0 ? Math.max(1, rows - 1 - addedRows - 1) : 0;

    return (
        <div className="py-2" aria-hidden data-testid="changes-diff-skeleton">
            {Array.from({ length: rows }, (_, index) => {
                const kind = index === 0 ? 'context' : index <= deletedRows ? 'deleted' : index <= deletedRows + addedRows ? 'added' : 'context';

                return (
                    <div key={index} className={cn('grid h-5 grid-cols-[42px_16px_minmax(0,1fr)] items-center', kind === 'added' && 'bg-ok-soft', kind === 'deleted' && 'bg-fail-soft')}>
                        <span />
                        <span />
                        {kind === 'context' ? (
                            <Skeleton className="h-[7px] rounded-[4px]" style={{ width: WIDTHS[index % WIDTHS.length] }} />
                        ) : (
                            <span className={cn('h-[7px] rounded-[4px]', kind === 'added' ? 'bg-ok/25' : 'bg-fail/25')} style={{ width: WIDTHS[index % WIDTHS.length] }} />
                        )}
                    </div>
                );
            })}
        </div>
    );
}
