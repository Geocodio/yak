import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { ThreadRow } from '@/components/shell/ThreadRow';
import type { ThreadListRow } from '@/types/shell';

export function ThreadGroup({
    groupKey,
    title,
    count,
    rows,
    activeThreadId = null,
    collapsible = false,
    defaultCollapsed = false,
    testId,
}: {
    groupKey: string;
    title: string;
    count?: number;
    rows: ThreadListRow[];
    activeThreadId?: string | null;
    collapsible?: boolean;
    defaultCollapsed?: boolean;
    testId?: string;
}) {
    const [isCollapsed, setIsCollapsed] = useState(defaultCollapsed);

    if (rows.length === 0) {
        return null;
    }

    return (
        <section className="flex flex-col gap-px" data-testid={testId ?? `thread-group-${groupKey}`} aria-label={title}>
            <h3 className="flex items-center gap-2 px-2 pb-1 pt-3 text-[11px] font-medium uppercase tracking-wide text-faint">
                {collapsible ? (
                    <button
                        type="button"
                        aria-expanded={!isCollapsed}
                        onClick={() => setIsCollapsed((value) => !value)}
                        className="flex items-center gap-1 uppercase tracking-wide hover:text-body"
                    >
                        <ChevronRight size={11} aria-hidden className={isCollapsed ? '' : 'rotate-90'} />
                        {title}
                    </button>
                ) : (
                    title
                )}
                {count !== undefined && count > 0 ? (
                    <span className="tnum rounded-pill bg-brand-soft px-1.5 text-[10.5px] text-brand">{count}</span>
                ) : null}
            </h3>
            {isCollapsed ? null : rows.map((row) => (
                <ThreadRow key={row.id} row={row} active={row.id === activeThreadId} />
            ))}
        </section>
    );
}
