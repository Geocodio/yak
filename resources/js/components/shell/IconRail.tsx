import { Link, router, usePage } from '@inertiajs/react';
import { BrandMarkIcon, Menu, Tooltip, cn } from '@geocodio/console-ui';
import { BookOpen, Layers, LayoutList, LogOut, Settings as SettingsIcon, Gauge } from 'lucide-react';
import type { ComponentType } from 'react';
import type { RailSection } from '@/components/shell/sections';
import { YAK_MARK } from '@/lib/brand';
import { logout, tasks } from '@/routes';
import type { SharedProps } from '@/types/shared';

export const SECTION_ICONS: Record<RailSection, { label: string; icon: ComponentType<{ size?: number }> }> = {
    threads: { label: 'Threads', icon: LayoutList },
    operations: { label: 'Operations', icon: Layers },
    insights: { label: 'Insights', icon: Gauge },
    settings: { label: 'Settings', icon: SettingsIcon },
};

function RailButton({ section, active, badge, onSelect }: { section: RailSection; active: boolean; badge?: number; onSelect: (section: RailSection) => void }) {
    const { label, icon: Icon } = SECTION_ICONS[section];

    return (
        <Tooltip label={label} side="right">
            <button
                type="button"
                aria-label={label}
                aria-current={active ? 'true' : undefined}
                onClick={() => onSelect(section)}
                data-testid={`rail-${section}`}
                className={cn(
                    'relative flex h-9 w-9 items-center justify-center rounded-control text-muted hover:bg-panel-2 hover:text-body',
                    active && 'bg-brand-soft text-brand hover:bg-brand-soft hover:text-brand',
                )}
            >
                <Icon size={18} />
                {badge && badge > 0 ? (
                    <span
                        data-testid="rail-threads-badge"
                        className="tnum absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-pill bg-brand px-1 text-[10px] font-medium text-white"
                    >
                        {badge}
                    </span>
                ) : null}
            </button>
        </Tooltip>
    );
}

export function IconRail({ active, onSelect }: { active: RailSection; onSelect: (section: RailSection) => void }) {
    const { props } = usePage<SharedProps>();
    const user = props.auth.user;

    return (
        <div className="flex w-[52px] shrink-0 flex-col items-center gap-2 border-r border-hair py-3" data-testid="icon-rail">
            <Link href={tasks.url()} aria-label="Yak home" className="mb-2 flex h-8 w-8 items-center justify-center" data-testid="app-brand">
                <BrandMarkIcon mark={YAK_MARK} size={22} />
            </Link>
            <RailButton section="threads" active={active === 'threads'} badge={props.nav.needsYouCount} onSelect={onSelect} />
            <RailButton section="operations" active={active === 'operations'} onSelect={onSelect} />
            <RailButton section="insights" active={active === 'insights'} onSelect={onSelect} />
            <div className="mt-auto flex flex-col items-center gap-2">
                <RailButton section="settings" active={active === 'settings'} onSelect={onSelect} />
                {user ? (
                    <Menu
                        trigger={
                            <span
                                className="flex h-8 w-8 items-center justify-center rounded-pill bg-panel-2 text-[11px] font-medium"
                                aria-label={user.name}
                                data-testid="rail-user"
                            >
                                {user.initials}
                            </span>
                        }
                        className="h-8 border-0 bg-transparent p-0 shadow-none"
                        items={[
                            {
                                key: 'help',
                                label: 'Help & docs',
                                icon: <BookOpen size={14} />,
                                onSelect: () => window.open(props.docs.baseUrl, '_blank', 'noopener'),
                            },
                            { key: 'logout', label: 'Log out', icon: <LogOut size={14} />, dividerAbove: true, onSelect: () => router.post(logout.url()) },
                        ]}
                    />
                ) : null}
            </div>
        </div>
    );
}
