import { ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';

export function PageHeader({
    title,
    crumbs,
    actions,
    children,
}: {
    title?: ReactNode;
    crumbs?: ReactNode[];
    actions?: ReactNode;
    children?: ReactNode;
}) {
    return (
        <header className="flex h-12 shrink-0 items-center gap-3 border-b border-hair bg-app px-5">
            <div className="flex min-w-0 items-center gap-1.5 text-[13px]">
                {crumbs?.map((c, i) => (
                    <span key={i} className="flex items-center gap-1.5">
                        {i > 0 && <ChevronRight size={12} className="text-faint" />}
                        <span className={i === crumbs.length - 1 && !title ? 'font-medium text-body' : 'text-muted'}>{c}</span>
                    </span>
                ))}
                {title && (
                    <>
                        {crumbs?.length ? <ChevronRight size={12} className="text-faint" /> : null}
                        <span className="truncate font-medium text-body">{title}</span>
                    </>
                )}
            </div>
            {children}
            <div className="ml-auto flex items-center gap-2">{actions}</div>
        </header>
    );
}
