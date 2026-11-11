import { Badge, cn } from '@geocodio/console-ui';
import type { ReactNode } from 'react';

/** One limit as a labelled bar; `blocking` marks the limit that holds the thread back. */
export function CapacityMeter({ label, used, limit, blocking = false, children }: { label: string; used: number; limit: number; blocking?: boolean; children?: ReactNode }) {
    const isFull = used >= limit;
    const percent = limit > 0 ? Math.min(100, (used / limit) * 100) : 0;

    return (
        <div data-testid="capacity-meter">
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-[13px]">
                <span className="text-body">{label}</span>
                <span className="flex items-center gap-2 text-muted">
                    {isFull && <Badge tone="warn">Full</Badge>}
                    {children ?? `${used} of ${limit}`}
                </span>
            </div>
            <div className="mt-1.5 h-1.5 overflow-hidden rounded-pill bg-panel-2" role="progressbar" aria-label={label} aria-valuemin={0} aria-valuemax={limit} aria-valuenow={Math.min(used, limit)}>
                <div className={cn('h-full rounded-pill', blocking ? 'bg-warn' : 'bg-brand')} style={{ width: `${percent}%` }} />
            </div>
        </div>
    );
}
