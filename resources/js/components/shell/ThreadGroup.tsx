import { ThreadRow } from '@/components/shell/ThreadRow';
import type { ThreadListRow } from '@/types/shell';

export function ThreadGroup({
    groupKey,
    title,
    count,
    rows,
    activeThreadId = null,
}: {
    groupKey: string;
    title: string;
    count?: number;
    rows: ThreadListRow[];
    activeThreadId?: number | null;
}) {
    if (rows.length === 0) {
        return null;
    }

    return (
        <section className="flex flex-col gap-px" data-testid={`thread-group-${groupKey}`} aria-label={title}>
            <h3 className="flex items-center gap-2 px-2 pb-1 pt-3 text-[11px] font-medium uppercase tracking-wide text-faint">
                {title}
                {count !== undefined && count > 0 ? (
                    <span className="tnum rounded-pill bg-brand-soft px-1.5 text-[10.5px] text-brand">{count}</span>
                ) : null}
            </h3>
            {rows.map((row) => (
                <ThreadRow key={row.id} row={row} active={row.id === activeThreadId} />
            ))}
        </section>
    );
}
