import { cn } from '@geocodio/console-ui';

export type TaskTab = 'overview' | 'activity' | 'details';

const TABS: { key: TaskTab; label: string }[] = [
    { key: 'overview', label: 'Overview' },
    { key: 'activity', label: 'Activity' },
    { key: 'details', label: 'Details' },
];

/** The phone task page's three panels, as an underlined tab strip under the header. Hidden from `lg` up, where the aside shows everything at once. */
export function TaskTabs({ value, onChange, activityCount }: { value: TaskTab; onChange: (tab: TaskTab) => void; activityCount: number }) {
    return (
        <div role="tablist" aria-label="Task sections" data-testid="task-tabs-mobile" className="flex shrink-0 gap-6 border-b border-hair bg-app px-4 lg:hidden">
            {TABS.map((tab) => {
                const active = tab.key === value;
                return (
                    <button
                        key={tab.key}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        data-testid={`task-tab-${tab.key}`}
                        onClick={() => onChange(tab.key)}
                        className={cn('relative flex h-11 items-center gap-1.5 text-[14px]', active ? 'font-medium text-body' : 'text-muted')}
                    >
                        {tab.label}
                        {tab.key === 'activity' && activityCount > 0 && <span className="tnum text-[11px] text-faint">{activityCount.toLocaleString()}</span>}
                        {active && <span className="absolute inset-x-0 -bottom-px h-0.5 rounded-full bg-accent" />}
                    </button>
                );
            })}
        </div>
    );
}
