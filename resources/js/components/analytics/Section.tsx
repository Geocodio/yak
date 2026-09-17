import { cn } from '@geocodio/console-ui';
import type { ReactNode } from 'react';

/** A dashboard card: title, one-line hint, then the content. */
export function Section({
    title,
    hint,
    action,
    children,
    className,
    testId,
}: {
    title: string;
    hint?: string;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
    testId?: string;
}) {
    return (
        <section className={cn('rounded-card border border-hair bg-panel p-4 shadow-card', className)} data-testid={testId}>
            <div className="mb-3 flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                <div className="min-w-0">
                    <h2 className="text-[13px] font-semibold">{title}</h2>
                    {hint && <p className="mt-0.5 text-[11px] text-muted">{hint}</p>}
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}

export function Empty({ children }: { children: ReactNode }) {
    return <p className="py-10 text-center text-[13px] text-muted">{children}</p>;
}

/** Bleeds a table to the card edge so its rows run the full width. */
export function TableScroll({ children, className }: { children: ReactNode; className?: string }) {
    return <div className={cn('-mx-4 -mb-4 overflow-x-auto', className)}>{children}</div>;
}

/** A compact label/value pair for secondary figures (tokens, review counts). */
export function MiniStat({ label, value, sub }: { label: string; value: string; sub?: string }) {
    return (
        <div className="rounded-control bg-panel-2 px-3 py-2">
            <div className="text-[11px] text-faint">{label}</div>
            <div className="mt-0.5 text-[15px] font-semibold tracking-tight">{value}</div>
            {sub && <div className="text-[11px] text-muted">{sub}</div>}
        </div>
    );
}

/** A legend swatch for tables that sit beside a chart. */
export function Swatch({ color }: { color: string }) {
    return <span className="inline-block h-2.5 w-2.5 shrink-0 rounded-[2px]" style={{ backgroundColor: color }} aria-hidden="true" />;
}
