import { cn } from '@geocodio/console-ui';
import type { ReactNode } from 'react';
import { InfoTip } from '@/components/threads/environment/InfoTip';

/** One titled group of the Overview rail, separated from the next by a hairline. */
export function OverviewGroup({ title, icon, aside, testId, children }: { title?: string; icon?: ReactNode; aside?: ReactNode; testId?: string; children: ReactNode }) {
    return (
        <section className="border-b border-hair px-4 py-3.5 last:border-b-0" data-testid={testId}>
            {title !== undefined && (
                <h3 className="mb-2 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.06em] text-muted">
                    {icon}
                    {title}
                    {aside}
                </h3>
            )}
            {children}
        </section>
    );
}

/** A definition list laid out as label and value columns. */
export function OverviewList({ children, className }: { children: ReactNode; className?: string }) {
    return <dl className={cn('m-0 grid grid-cols-[86px_minmax(0,1fr)] items-start gap-x-2.5 gap-y-2.5 text-[13px]', className)}>{children}</dl>;
}

/** A label and its value. The test id sits on the value, which is what a person reads. */
export function OverviewRow({ label, testId, tip, children }: { label: string; testId: string; tip?: string; children: ReactNode }) {
    return (
        <>
            <dt className="flex items-center gap-1 pt-[3px] text-muted">
                {label}
                {tip !== undefined && <InfoTip label={tip} />}
            </dt>
            <dd className="m-0 flex min-h-[26px] min-w-0 flex-wrap items-center gap-1 text-body" data-testid={testId}>
                {children}
            </dd>
        </>
    );
}

/** A line under a row's value, such as a status or a hint. */
export function RowNote({ children, className }: { children: ReactNode; className?: string }) {
    return <span className={cn('flex basis-full items-center gap-1.5 text-[12px] text-muted', className)}>{children}</span>;
}
