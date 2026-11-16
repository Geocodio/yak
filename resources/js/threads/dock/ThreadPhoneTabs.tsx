import { cn } from '@geocodio/console-ui';
import { phoneTabs, type PhoneTabId } from './dockState';

type Props = { registeredDockIds: string[]; active: PhoneTabId; isWorking: boolean; changesCount: number; onSelect: (id: PhoneTabId) => void };

/** Chat, Preview, Files, Terminal and Overview as one tab row under `lg`; desktop shows the views side by side instead. */
export function ThreadPhoneTabs({ registeredDockIds, active, isWorking, changesCount, onSelect }: Props) {
    return (
        <div role="tablist" aria-label="Thread views" className="flex shrink-0 gap-4 overflow-x-auto border-b border-hair px-4 [scrollbar-width:none] lg:hidden" data-testid="thread-phone-tabs">
            {phoneTabs(registeredDockIds).map((tab) => (
                <button
                    key={tab.id}
                    type="button"
                    role="tab"
                    aria-selected={tab.id === active}
                    data-testid={`thread-phone-tab-${tab.id}`}
                    onClick={() => onSelect(tab.id)}
                    className={cn('-mb-px flex h-[42px] shrink-0 cursor-pointer items-center gap-1.5 whitespace-nowrap border-b-2 text-[13.5px]', tab.id === active ? 'border-accent font-medium text-body' : 'border-transparent text-muted')}
                >
                    {tab.label}
                    {tab.id === 'chat' && isWorking && <span className="size-1.5 animate-pulse rounded-pill bg-info motion-reduce:animate-none" data-testid="thread-phone-tab-chat-dot" aria-label="Claude is working" />}
                    {tab.id === 'files' && changesCount > 0 && <span className="inline-grid h-[17px] min-w-[17px] place-items-center rounded-pill bg-panel-2 px-1.5 text-[11px] font-medium tabular-nums text-muted">{changesCount}</span>}
                </button>
            ))}
        </div>
    );
}
