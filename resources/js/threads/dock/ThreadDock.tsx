import { cn } from '@geocodio/console-ui';
import { useEffect } from 'react';
import type { Panel } from '@/threads/panels';
import type { ThreadData } from '@/types/threads';

type Props = {
    thread: ThreadData;
    canDrive: boolean;
    panels: Panel[];
    active: Panel | null;
    onSelect: (id: string | null) => void;
};

/** Segmented buttons that open and close dock panels; the open panel renders through `ThreadDockPanel`. */
export function ThreadDockToggles({ panels, active, onSelect }: Pick<Props, 'panels' | 'active' | 'onSelect'>) {
    if (panels.length === 0) {
        return null;
    }

    return (
        <div role="group" aria-label="Dock panels" className="hidden shrink-0 gap-px rounded-chip border border-hair bg-hair lg:flex" data-testid="thread-dock-toggles">
            {panels.map((panel) => (
                <button
                    key={panel.id}
                    type="button"
                    aria-pressed={panel.id === active?.id}
                    data-testid={`thread-dock-toggle-${panel.id}`}
                    onClick={() => onSelect(panel.id === active?.id ? null : panel.id)}
                    className={cn('cursor-pointer bg-app px-3 py-1 text-[12.5px]', panel.id === active?.id ? 'font-medium text-body' : 'text-muted hover:text-body')}
                >
                    {panel.label}
                </button>
            ))}
        </div>
    );
}

/** The open dock panel, to the right of the conversation. While it is open the page sets `data-thread-docked` on the body so the details sidebar shrinks. */
export function ThreadDockPanel({ thread, canDrive, active, isPhoneVisible }: Pick<Props, 'thread' | 'canDrive' | 'active'> & { isPhoneVisible: boolean }) {
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
            className={cn('min-h-0 min-w-0 flex-1 flex-col border-l border-hair bg-app lg:flex', isPhoneVisible ? 'flex' : 'hidden')}
            aria-label={active.label}
            data-testid="thread-dock"
        >
            <active.component thread={thread} canDrive={canDrive} />
        </section>
    );
}
