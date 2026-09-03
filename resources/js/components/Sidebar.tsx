import { Link, router, usePage } from '@inertiajs/react';
import { Kbd, Menu, Tooltip, cn } from '@geocodio/console-ui';
import {
    BookOpen,
    ChevronDown,
    ClipboardList,
    Code2,
    DollarSign,
    ExternalLink,
    Heart,
    Inbox,
    LogOut,
    MessageSquare,
    Plus,
    Puzzle,
    Rocket,
    Search,
    Server,
    Settings as SettingsIcon,
    Type,
} from 'lucide-react';
import type { ComponentType } from 'react';
import { channels, costs, deployments, health, logout, mcp, prReviews, prompts, repos, skills, tasks } from '@/routes';
import { edit as profileEdit } from '@/routes/profile';
import type { SharedProps } from '@/types/shared';

export type NavItem = {
    label: string;
    icon: ComponentType<{ size?: number; className?: string }>;
    url: string;
};

export const NAV_ITEMS: NavItem[] = [
    { label: 'Tasks', icon: ClipboardList, url: tasks.url() },
    { label: 'Repositories', icon: Code2, url: repos.url() },
    { label: 'Deployments', icon: Rocket, url: deployments.url() },
    { label: 'PR Reviews', icon: MessageSquare, url: prReviews.url() },
    { label: 'Prompts', icon: Type, url: prompts.url() },
    { label: 'Costs', icon: DollarSign, url: costs.url() },
    { label: 'Skills', icon: Puzzle, url: skills.url() },
    { label: 'MCP servers', icon: Server, url: mcp.url() },
    { label: 'Health', icon: Heart, url: health.url() },
];

/**
 * Pages that live under Settings and so are deliberately absent from the
 * sidebar, but that the command palette still offers so they stay one
 * keystroke away.
 */
export const SECONDARY_NAV_ITEMS: NavItem[] = [{ label: 'Channels', icon: Inbox, url: channels.url() }];

export const SETTINGS_ITEM: NavItem = { label: 'Settings', icon: SettingsIcon, url: profileEdit.url() };

export function openNewTask(): void {
    router.visit(tasks.url({ query: { new: 1 } }));
    window.dispatchEvent(new CustomEvent('yak:new-task'));
}

export function openPalette(): void {
    window.dispatchEvent(new CustomEvent('yak:open-palette'));
}

export function isActive(currentUrl: string, itemUrl: string): boolean {
    return currentUrl.split('?')[0].startsWith(itemUrl);
}

export function Sidebar() {
    const { props, url } = usePage<SharedProps>();
    const user = props.auth.user;

    return (
        <aside className="flex w-[220px] shrink-0 flex-col border-r border-hair bg-sidebar">
            <div className="flex h-12 items-center justify-between px-3">
                <Menu
                    trigger={
                        <span className="flex items-center gap-2 text-[13px]">
                            <span className="flex h-5 w-5 items-center justify-center rounded-chip bg-accent text-[11px] font-bold text-accent-ink">
                                G
                            </span>
                            <span className="font-medium">Geocodio</span>
                            <ChevronDown size={12} className="text-faint" />
                        </span>
                    }
                    className="h-7 border-0 bg-transparent px-1.5 shadow-none hover:bg-panel-2"
                    items={[
                        {
                            key: 'settings',
                            label: 'Settings',
                            icon: <SettingsIcon size={14} />,
                            onSelect: () => router.visit(SETTINGS_ITEM.url),
                        },
                        {
                            key: 'help',
                            label: 'Help & docs',
                            icon: <BookOpen size={14} />,
                            onSelect: () => window.open(props.docs.baseUrl, '_blank', 'noopener'),
                        },
                        {
                            key: 'logout',
                            label: 'Log out',
                            icon: <LogOut size={14} />,
                            dividerAbove: true,
                            onSelect: () => router.post(logout.url()),
                        },
                    ]}
                />
                <Tooltip label="New task">
                    <button
                        type="button"
                        onClick={openNewTask}
                        className="flex h-6 w-6 items-center justify-center rounded-control text-muted hover:bg-panel-2 hover:text-body"
                    >
                        <Plus size={14} />
                    </button>
                </Tooltip>
            </div>

            <button
                type="button"
                onClick={openPalette}
                className="mx-3 mb-3 flex h-7 items-center gap-2 rounded-control border border-hair bg-panel px-2 text-[12px] text-faint shadow-card hover:border-hair-strong"
            >
                <Search size={13} />
                <span className="flex-1 text-left">Search…</span>
                <Kbd keys={['⌘', 'K']} />
            </button>

            <nav className="flex flex-col gap-px px-2">
                {NAV_ITEMS.map((item) => {
                    const active = isActive(url, item.url);
                    const Icon = item.icon;
                    return (
                        <Link
                            key={item.url}
                            href={item.url}
                            aria-current={active ? 'page' : undefined}
                            className={cn(
                                'flex h-7 items-center gap-2 rounded-control px-2 text-[13px] text-muted hover:bg-panel-2 hover:text-body',
                                active && 'bg-accent-soft font-medium text-accent-text hover:bg-accent-soft hover:text-accent-text',
                            )}
                        >
                            <Icon size={15} />
                            <span className="flex-1">{item.label}</span>
                            {item.label === 'Tasks' && props.nav.activeTaskCount > 0 ? (
                                <span className="tnum text-[11px] text-faint">{props.nav.activeTaskCount}</span>
                            ) : null}
                        </Link>
                    );
                })}
            </nav>

            <div className="mt-auto flex flex-col gap-px p-2">
                <Link
                    href={SETTINGS_ITEM.url}
                    aria-current={isActive(url, SETTINGS_ITEM.url) ? 'page' : undefined}
                    className={cn(
                        'flex h-7 items-center gap-2 rounded-control px-2 text-[13px] text-muted hover:bg-panel-2 hover:text-body',
                        isActive(url, SETTINGS_ITEM.url) && 'bg-accent-soft font-medium text-accent-text',
                    )}
                >
                    <SettingsIcon size={15} />
                    Settings
                </Link>
                <a
                    href={props.docs.baseUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex h-7 items-center gap-2 rounded-control px-2 text-[13px] text-muted hover:bg-panel-2 hover:text-body"
                >
                    <BookOpen size={15} />
                    <span className="flex-1">Help</span>
                    <ExternalLink size={12} className="text-faint" />
                </a>
                {user ? (
                    <div className="mt-2 flex items-center gap-2 px-2 py-1.5">
                        <span className="flex h-6 w-6 items-center justify-center rounded-pill bg-panel-2 text-[11px] font-medium">
                            {user.initials}
                        </span>
                        <div className="min-w-0 leading-tight">
                            <div className="truncate text-[12px] font-medium">{user.name}</div>
                            <div className="truncate text-[11px] text-faint">{user.email}</div>
                        </div>
                    </div>
                ) : null}
            </div>
        </aside>
    );
}
