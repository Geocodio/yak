import { cn } from '@geocodio/console-ui';
import { Plus, X } from 'lucide-react';

export const MAX_TERMINAL_TABS = 4;

export type TerminalTab = { id: number; title: string };

type Props = {
    tabs: TerminalTab[];
    activeId: number;
    onSelect: (id: number) => void;
    onAdd: () => void;
    onClose: (id: number) => void;
};

export function TerminalTabs({ tabs, activeId, onSelect, onAdd, onClose }: Props) {
    return (
        <div role="tablist" aria-label="Shell sessions" className="flex min-w-0 items-center gap-1 overflow-x-auto">
            {tabs.map((tab) => (
                <div
                    key={tab.id}
                    className={cn('flex h-7 shrink-0 items-center rounded-md px-2 text-[13px]', tab.id === activeId ? 'bg-app font-medium text-body' : 'text-muted')}
                >
                    <button type="button" role="tab" aria-selected={tab.id === activeId} data-testid={`terminal-tab-${tab.id}`} onClick={() => onSelect(tab.id)}>
                        {tab.title}
                    </button>
                    {tabs.length > 1 && (
                        <button type="button" aria-label={`Close ${tab.title}`} data-testid={`terminal-tab-close-${tab.id}`} className="ml-1.5 text-muted" onClick={() => onClose(tab.id)}>
                            <X size={12} />
                        </button>
                    )}
                </div>
            ))}
            {tabs.length < MAX_TERMINAL_TABS && (
                <button type="button" aria-label="New shell" data-testid="terminal-tab-add" className="flex h-7 w-7 shrink-0 items-center justify-center text-muted" onClick={onAdd}>
                    <Plus size={14} />
                </button>
            )}
        </div>
    );
}
