import { BookOpen } from 'lucide-react';
import { Skeleton } from '@geocodio/console-ui';
import type { ReactNode } from 'react';

/** Opening lines of a section: its name and one sentence on what lives there. */
export function SectionIntro({ title, description }: { title: string; description?: ReactNode }) {
    return (
        <div className="mb-4">
            <h2 className="text-[18px] font-bold">{title}</h2>
            {description && <p className="mt-1 text-[13px] leading-relaxed text-muted">{description}</p>}
        </div>
    );
}

/** One group of related settings. Rows inside are separated by hairlines; see `SettingRow`. */
export function SettingsCard({
    title,
    description,
    aside,
    docsHref,
    children,
    testId,
}: {
    title?: string;
    description?: ReactNode;
    aside?: ReactNode;
    docsHref?: string;
    children: ReactNode;
    testId?: string;
}) {
    return (
        <section className="mb-4 flex flex-col gap-4 rounded-card border border-hair bg-panel p-5 shadow-card" data-testid={testId}>
            {(title || aside) && (
                <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                    <div>
                        {title && <h3 className="text-[14px] font-semibold">{title}</h3>}
                        {description && <p className="mt-0.5 text-[13px] leading-relaxed text-muted">{description}</p>}
                        {docsHref && (
                            <a href={docsHref} target="_blank" rel="noopener noreferrer" className="mt-1 inline-flex items-center gap-1 text-[12px] text-accent-text hover:underline">
                                <BookOpen size={12} /> Docs
                            </a>
                        )}
                    </div>
                    {aside && <div className="flex flex-wrap items-center gap-2">{aside}</div>}
                </div>
            )}
            <div className="flex flex-col">{children}</div>
        </section>
    );
}

/** A label and description on the left, the control on the right. */
export function SettingRow({
    label,
    description,
    badge,
    children,
    below,
}: {
    label: ReactNode;
    description?: ReactNode;
    badge?: ReactNode;
    children?: ReactNode;
    below?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 border-t border-hair py-3 first:border-t-0 first:pt-0 last:pb-0">
            <div className="min-w-0 flex-1 basis-60">
                <div className="flex flex-wrap items-center gap-2 text-[13px] font-medium">
                    {label}
                    {badge}
                </div>
                {description && <p className="mt-0.5 text-[12px] leading-relaxed text-muted">{description}</p>}
                {below}
            </div>
            {children !== undefined && <div className="shrink-0">{children}</div>}
        </div>
    );
}

export function FieldSkeleton({ label, height }: { label: string; height: string }) {
    return (
        <div className="flex flex-col gap-1.5" data-testid="yak-config-field-skeleton">
            <div className="text-[12px] font-medium">{label}</div>
            <Skeleton className={`${height} w-full rounded-control`} />
        </div>
    );
}

/** Stacks the fields of a card with the spacing form fields use. */
export function FieldStack({ children }: { children: ReactNode }) {
    return <div className="flex flex-col gap-4">{children}</div>;
}
