import { cn } from '@geocodio/console-ui';
import type { Panel } from '@/threads/panels';
import type { ThreadData } from '@/types/threads';

type ToolbarProps = { panels: Panel[]; activeId: string | null; onChange: (id: string | null) => void };

/** Segmented buttons that open and close dock panels; pressing the open one closes the dock. */
export function DockToolbar({ panels, activeId, onChange }: ToolbarProps) {
    if (panels.length === 0) {
        return null;
    }

    return (
        <div className="flex shrink-0 gap-0.5 rounded-lg bg-panel-2 p-0.5" role="group" aria-label="Dock panels" data-testid="dock-toolbar">
            {panels.map((panel) => (
                <button
                    key={panel.id}
                    type="button"
                    aria-pressed={panel.id === activeId}
                    data-testid={`dock-toggle-${panel.id}`}
                    onClick={() => onChange(panel.id === activeId ? null : panel.id)}
                    className={cn('h-7 rounded-md px-3 text-[13px]', panel.id === activeId ? 'bg-app font-medium text-body shadow-sm' : 'text-muted')}
                >
                    {panel.label}
                </button>
            ))}
        </div>
    );
}

type AreaProps = { panel: Panel; thread: ThreadData; canDrive: boolean };

/** The open dock panel: beside the conversation on wide screens, instead of it on narrow ones. */
export function DockArea({ panel, thread, canDrive }: AreaProps) {
    return (
        <section className="flex min-h-0 min-w-0 flex-1 flex-col border-l border-hair lg:flex-[1.4]" data-testid="thread-dock" aria-label={panel.label}>
            <panel.component thread={thread} canDrive={canDrive} />
        </section>
    );
}
