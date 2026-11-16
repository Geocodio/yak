import { cn } from '@geocodio/console-ui';
import { Folder, Globe, Terminal, type LucideIcon } from 'lucide-react';
import { useEffect } from 'react';
import type { Panel, PanelProps } from '@/threads/panels';
import type { ThreadData } from '@/types/threads';

const ICONS: Record<string, LucideIcon> = { preview: Globe, files: Folder, terminal: Terminal };

type TogglesProps = {
    thread: ThreadData;
    panels: Panel[];
    active: Panel | null;
    diff: PanelProps['diff'];
    onSelect: (id: string | null) => void;
};

/** The header's view switcher: segmented buttons that open and close dock views; the open view renders through `ThreadDockPanel`. */
export function ThreadDockToggles({ thread, panels, active, diff, onSelect }: TogglesProps) {
    if (panels.length === 0) {
        return null;
    }

    return (
        <div role="group" aria-label="Views" className="hidden shrink-0 gap-0.5 rounded-[9px] bg-panel-2 p-0.5 lg:inline-flex" data-testid="thread-dock-toggles">
            {panels.map((panel) => {
                const isActive = panel.id === active?.id;
                const Icon = ICONS[panel.id];

                return (
                    <button
                        key={panel.id}
                        type="button"
                        aria-pressed={isActive}
                        data-testid={`thread-dock-toggle-${panel.id}`}
                        onClick={() => onSelect(isActive ? null : panel.id)}
                        className={cn(
                            'inline-flex h-7 cursor-pointer items-center gap-1.5 whitespace-nowrap rounded-[7px] px-2.5 text-[13px]',
                            isActive ? 'bg-panel font-medium text-body shadow-[0_0_0_1px_var(--hair),0_1px_2px_rgb(0_0_0/0.1)]' : 'text-muted hover:text-body',
                        )}
                    >
                        {Icon !== undefined && <Icon size={13} aria-hidden />}
                        {panel.label}
                        {panel.badge !== undefined && (
                            <span
                                className={cn('inline-grid h-[17px] min-w-[17px] place-items-center rounded-pill px-1.5 text-[11px] font-medium tabular-nums empty:hidden', isActive ? 'bg-(--accent-tint) text-accent-text' : 'bg-panel-2 text-muted')}
                                data-testid={`thread-dock-count-${panel.id}`}
                            >
                                <panel.badge thread={thread} diff={diff} />
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}

/** The open view, to the right of the conversation. While it is open the page sets `data-thread-docked` on the body so the shell steps back to its icon rail. */
export function ThreadDockPanel({ active, isPhoneVisible, panelProps }: { active: Panel | null; isPhoneVisible: boolean; panelProps: PanelProps }) {
    useEffect(() => {
        if (active === null) {
            return;
        }

        document.body.dataset.threadDocked = 'true';

        return () => {
            delete document.body.dataset.threadDocked;
        };
    }, [active]);

    if (active === null) {
        return null;
    }

    return (
        <section
            className={cn('min-h-0 min-w-0 flex-1 flex-col border-l border-hair bg-panel lg:flex', isPhoneVisible ? 'flex' : 'hidden')}
            aria-label={active.label}
            data-testid="thread-dock"
        >
            <active.component {...panelProps} />
        </section>
    );
}
