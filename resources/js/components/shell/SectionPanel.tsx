import { Link, usePage } from '@inertiajs/react';
import { cn } from '@geocodio/console-ui';
import { SECTION_COPY } from '@/components/shell/sections';
import type { RailSection } from '@/components/shell/sections';
import { isActive } from '@/components/Sidebar';

/** The Operations, Insights and Settings drawers: a description line and the section's pages. */
export function SectionPanel({ section }: { section: Exclude<RailSection, 'threads'> }) {
    const { url } = usePage();
    const { title, description, items } = SECTION_COPY[section];

    return (
        <div className="flex min-h-0 flex-1 flex-col" data-testid={`section-panel-${section}`}>
            <div className="flex h-12 items-center px-3 text-[13.5px] font-semibold tracking-tight">{title}</div>
            <p className="px-3 pb-3 text-[12px] text-muted">{description}</p>
            <nav className="flex flex-col gap-px px-2" aria-label={title}>
                {items.map((item) => {
                    const active = isActive(url, item.url);
                    const Icon = item.icon;
                    return (
                        <Link
                            key={item.url}
                            href={item.url}
                            aria-current={active ? 'page' : undefined}
                            className={cn(
                                'flex h-8 items-center gap-2 rounded-control px-2 text-[13px] text-muted hover:bg-panel-2 hover:text-body',
                                active && 'bg-brand-soft font-medium text-brand hover:bg-brand-soft hover:text-brand',
                            )}
                        >
                            <Icon size={15} />
                            <span className="flex-1">{item.label}</span>
                        </Link>
                    );
                })}
            </nav>
        </div>
    );
}
