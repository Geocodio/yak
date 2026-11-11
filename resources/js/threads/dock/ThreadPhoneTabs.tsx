import { cn } from '@geocodio/console-ui';
import { phoneTabs, type PhoneTabId } from './dockState';

type Props = { registeredDockIds: string[]; active: PhoneTabId; isWorking: boolean; onSelect: (id: PhoneTabId) => void };

/** Chat, Preview, Terminal and Details as one tab row under `lg`; desktop shows the panels side by side instead. */
export function ThreadPhoneTabs({ registeredDockIds, active, isWorking, onSelect }: Props) {
    return (
        <div role="tablist" aria-label="Thread views" className="flex shrink-0 gap-4 border-b border-hair px-4 lg:hidden" data-testid="thread-phone-tabs">
            {phoneTabs(registeredDockIds).map((tab) => (
                <button
                    key={tab.id}
                    type="button"
                    role="tab"
                    aria-selected={tab.id === active}
                    data-testid={`thread-phone-tab-${tab.id}`}
                    onClick={() => onSelect(tab.id)}
                    className={cn('flex h-11 cursor-pointer items-center text-[14px]', tab.id === active ? 'font-medium text-body' : 'text-muted')}
                >
                    {tab.label}
                    {tab.id === 'chat' && isWorking && <span className="ml-1.5 size-1.5 animate-pulse rounded-pill bg-info" data-testid="thread-phone-tab-chat-dot" aria-label="Claude is working" />}
                </button>
            ))}
        </div>
    );
}
