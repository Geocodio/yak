import { Link, usePage } from '@inertiajs/react';
import { PanelLeftClose } from 'lucide-react';
import { IconButton, cn } from '@geocodio/console-ui';
import { ADMIN_ONLY_URL, SECTION_COPY } from '@/components/shell/sections';
import type { RailSection } from '@/components/shell/sections';
import { isActive } from '@/components/Sidebar';
import { deployments, observations, prReviews, repos } from '@/routes';
import { edit as editRepository } from '@/routes/repos';
import type { SharedProps } from '@/types/shared';

type Trailing = { text: string; className: string };

/** Right-aligned live figure per Operations row; hidden when absent or zero. */
function operationsTrailing(panel: SharedProps['operationsPanel']): Record<string, Trailing | undefined> {
    if (!panel) {
        return {};
    }
    const entry = (count: number, text: string, className: string): Trailing | undefined => (count > 0 ? { text, className } : undefined);

    return {
        [repos.url()]: entry(panel.counts.repositories, String(panel.counts.repositories), 'text-muted'),
        [deployments.url()]: entry(panel.deploymentsRunning, `${panel.deploymentsRunning} running`, 'text-ok') ?? entry(panel.counts.deployments, String(panel.counts.deployments), 'text-muted'),
        [prReviews.url()]: entry(panel.prReviewsOpen, `${panel.prReviewsOpen} open`, 'text-muted') ?? entry(panel.counts.prReviews, String(panel.counts.prReviews), 'text-muted'),
        [observations.url()]: entry(panel.observationsNew, `${panel.observationsNew} new`, 'text-warn') ?? entry(panel.counts.observations, String(panel.counts.observations), 'text-muted'),
    };
}

/** The Operations, Insights and Settings drawers: a description line and the section's pages. */
export function SectionPanel({ section, touch = false, onNavigate, onCollapse }: { section: Exclude<RailSection, 'threads'>; touch?: boolean; onNavigate?: () => void; onCollapse?: () => void }) {
    const { url, props } = usePage<SharedProps>();
    const operationsPanel = section === 'operations' ? props.operationsPanel : null;
    const trailing = operationsTrailing(operationsPanel);
    const { title, description, items, groups } = SECTION_COPY[section];
    const isAdmin = props.auth.user?.isAdmin ?? false;
    const navGroups = (groups ?? [{ title: '', items: items ?? [] }]).map((group) => ({
        ...group,
        items: group.items.filter((item) => isAdmin || item.url !== ADMIN_ONLY_URL),
    }));

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
                                    {trailing[item.url] ? <span className={cn('text-[11.5px] tabular-nums', trailing[item.url]?.className)}>{trailing[item.url]?.text}</span> : null}
                                </Link>
                            );
                        })}
                    </div>
                ))}
            </nav>
            {operationsPanel && operationsPanel.repositories.length > 0 ? (
                <div className="flex flex-col gap-px px-2" data-testid="ops-repositories">
                    <div className="px-2 pb-1 pt-4 text-[11px] font-medium text-muted">Repositories</div>
                    {operationsPanel.repositories.map((repository) => (
                        <Link
                            key={repository.slug}
                            href={editRepository.url(repository.slug)}
                            onClick={onNavigate}
                            data-testid={`ops-repo-${repository.slug}`}
                            className={cn(touch ? 'min-h-11' : 'min-h-9', 'flex items-center gap-2 rounded-control px-2 py-1 hover:bg-panel-2')}
                        >
                            <span className="flex size-6 shrink-0 items-center justify-center rounded-control bg-panel-2 text-[9px] font-semibold text-muted">{repository.initials}</span>
                            <span className="flex min-w-0 flex-col">
                                <span className="truncate font-mono text-[12px] text-body">{repository.slug}</span>
                                <span className={cn('text-[11px]', repository.status.endsWith('needs you') ? 'text-brand' : 'text-muted')}>{repository.status}</span>
                            </span>
                        </Link>
                    ))}
                </div>
            ) : null}
        </div>
    );
}
