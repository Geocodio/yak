import { Link, router, usePage } from '@inertiajs/react';
import { BrandMarkIcon, Kbd, Menu, Tooltip, cn } from '@geocodio/console-ui';
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
import { YAK_MARK } from '@/lib/brand';
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

/** Asks the app shell to open the navigation drawer shown below `lg`. */
export function openMobileNav(): void {
    window.dispatchEvent(new CustomEvent('yak:open-nav'));
}

export function isActive(currentUrl: string, itemUrl: string): boolean {
    return currentUrl.split('?')[0].startsWith(itemUrl);
}

/**
 * The nav links, settings, help and signed-in user, shared by the desktop
 * sidebar and the mobile drawer. `touch` loosens the row height for a
 * thumb instead of a pointer; `onNavigate` lets the drawer close itself.
 */
export function SidebarNav({ touch = false, onNavigate }: { touch?: boolean; onNavigate?: () => void }) {
    const { props, url } = usePage<SharedProps>();
    const user = props.auth.user;

    const rowClass = cn(
        'flex items-center gap-2 rounded-control px-2 text-muted hover:bg-panel-2 hover:text-body',
        touch ? 'h-10 text-[14px]' : 'h-7 text-[13px]',
    );
    const activeClass = 'bg-brand-soft font-medium text-brand hover:bg-brand-soft hover:text-brand';
    const iconSize = touch ? 17 : 15;

    return (
        <>
            <nav className="flex flex-col gap-px px-2" aria-label="Main">
                {NAV_ITEMS.map((item) => {
                    const active = isActive(url, item.url);
                    const Icon = item.icon;
                    return (
                        <Link
                            key={item.url}
                            href={item.url}
                            aria-current={active ? 'page' : undefined}
                            onClick={onNavigate}
                            className={cn(rowClass, active && activeClass)}
                        >
                            <Icon size={iconSize} />
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
                    onClick={onNavigate}
                    className={cn(rowClass, isActive(url, SETTINGS_ITEM.url) && activeClass)}
                >
                    <SettingsIcon size={iconSize} />
                    Settings
                </Link>
                <a href={props.docs.baseUrl} target="_blank" rel="noopener noreferrer" className={rowClass}>
                    <BookOpen size={iconSize} />
                    <span className="flex-1">Help</span>
                    <ExternalLink size={12} className="text-faint" />
                </a>
                {touch ? (
                    <button type="button" onClick={() => router.post(logout.url())} className={rowClass}>
                        <LogOut size={iconSize} />
                        Log out
                    </button>
                ) : null}
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
        </>
    );
}

/** The desktop sidebar; hidden below `lg`, where `AppLayout` swaps in a top bar and drawer. */
export function Sidebar() {
    const { props } = usePage<SharedProps>();

    return (
        <aside className="hidden w-[220px] shrink-0 flex-col border-r border-hair bg-sidebar lg:flex" data-testid="sidebar">
            <div className="flex h-12 items-center justify-between px-3">
                <Menu
                    trigger={
                        <span className="flex items-center gap-2 text-[13.5px]" data-testid="app-brand">
                            <BrandMarkIcon mark={YAK_MARK} size={20} />
                            <span className="font-semibold tracking-tight text-body">Yak</span>
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

            <SidebarNav />
        </aside>
    );
}
