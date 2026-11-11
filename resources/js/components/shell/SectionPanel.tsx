import { Link, usePage } from '@inertiajs/react';
import { PanelLeftClose } from 'lucide-react';
import { IconButton, cn } from '@geocodio/console-ui';
import { SECTION_COPY } from '@/components/shell/sections';
import type { RailSection } from '@/components/shell/sections';
import { isActive } from '@/components/Sidebar';

/** The Operations, Insights and Settings drawers: a description line and the section's pages. */
export function SectionPanel({ section, touch = false, onNavigate, onCollapse }: { section: Exclude<RailSection, 'threads'>; touch?: boolean; onNavigate?: () => void; onCollapse?: () => void }) {
    const { url } = usePage();
    const { title, description, items, groups } = SECTION_COPY[section];
    const navGroups = groups ?? [{ title: '', items: items ?? [] }];

    return (
        <div className="flex min-h-0 flex-1 flex-col" data-testid={`section-panel-${section}`}>
            <div className="flex h-12 items-center justify-between px-3">
                <span className="text-[13.5px] font-semibold tracking-tight">{title}</span>
                {onCollapse ? (
                    <IconButton label="Collapse panel" onClick={onCollapse} data-testid="section-panel-collapse">
                        <PanelLeftClose size={14} />
                    </IconButton>
                ) : null}
            </div>
            {description ? <p className="px-3 pb-3 text-[12px] text-muted">{description}</p> : null}
            <nav className="flex flex-col gap-px px-2" aria-label={title}>
                {navGroups.map((group) => (
                    <div key={group.title} className="flex flex-col gap-px">
                        {group.title ? <div className="px-2 pb-1 pt-3 text-[11px] font-medium text-faint">{group.title}</div> : null}
                        {group.items.map((item) => {
                            const active = isActive(url, item.url);
                            const Icon = item.icon;
                            return (
                                <Link
                                    key={item.url}
                                    href={item.url}
                                    onClick={onNavigate}
                                    aria-current={active ? 'page' : undefined}
                                    className={cn(
                                        touch ? 'h-11 text-[15px]' : 'h-8 text-[13px]',
                                        'flex items-center gap-2 rounded-control px-2 text-muted hover:bg-panel-2 hover:text-body',
                                        active && 'bg-brand-soft font-medium text-brand hover:bg-brand-soft hover:text-brand',
                                    )}
                                >
                                    <Icon size={touch ? 18 : 15} />
                                    <span className="flex-1">{item.label}</span>
                                </Link>
                            );
                        })}
                    </div>
                ))}
            </nav>
        </div>
    );
}
