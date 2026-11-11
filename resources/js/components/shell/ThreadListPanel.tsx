import { usePage } from '@inertiajs/react';
import { Button, IconButton, Kbd, Skeleton, Tooltip, cn } from '@geocodio/console-ui';
import { CircleHelp, PanelLeftClose, Plus, Search } from 'lucide-react';
import { ThreadGroup } from '@/components/shell/ThreadGroup';
import { useThreadList } from '@/components/shell/useThreadList';
import { openNewTask, openPalette } from '@/components/Sidebar';
import type { SharedProps } from '@/types/shared';
import type { ThreadListFilter } from '@/types/shell';

const FILTERS: { value: ThreadListFilter; label: string }[] = [
    { value: 'mine', label: 'Mine' },
    { value: 'everyone', label: 'Everyone' },
];

const FILTER_HELP = 'Mine: threads you started or took part in, plus unattended threads on repos you follow.';

export function ThreadListPanel({ onCollapse }: { onCollapse: () => void }) {
    const { props, url } = usePage<SharedProps>();
    const { data, filter, setFilter, search, setSearch, isLoading } = useThreadList(props.threadList);
    const activeThreadId = Number(/^\/threads\/(\d+)/.exec(url.split('?')[0])?.[1] ?? 0) || null;
    const isEmpty = data !== null && data.needsYou.length === 0 && data.threads.length === 0;
    const rowsOf = (group: 'today' | 'this_week' | 'older') => (data?.threads ?? []).filter((row) => row.group === group);

    return (
        <div className="flex min-h-0 flex-1 flex-col" data-testid="thread-list-panel">
            <div className="flex h-12 items-center justify-between px-3">
                <span className="text-[13.5px] font-semibold tracking-tight">Threads</span>
                <IconButton label="Collapse thread list" onClick={onCollapse} data-testid="collapse-thread-list">
                    <PanelLeftClose size={14} />
                </IconButton>
            </div>
            <div className="flex flex-col gap-2 px-3 pb-2">
                <Button variant="primary" icon={<Plus size={14} />} onClick={openNewTask} className="w-full" data-testid="new-thread">
                    New thread
                </Button>
                <div className="flex h-7 items-center gap-2 rounded-control border border-hair bg-panel px-2 text-[12px] text-muted shadow-card focus-within:border-hair-strong">
                    <Search size={13} />
                    <input
                        type="search"
                        value={search}
                        maxLength={100}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search threads"
                        aria-label="Search threads"
                        data-testid="thread-search"
                        className="min-w-0 flex-1 bg-transparent text-body outline-none placeholder:text-muted"
                    />
                    <button type="button" onClick={openPalette} aria-label="Open command palette" className="flex">
                        <Kbd keys={['⌘', 'K']} />
                    </button>
                </div>
                <div className="flex items-center gap-1.5" data-testid="thread-filter">
                    <div role="group" aria-label="Thread filter" className="flex rounded-control bg-panel-2 p-0.5">
                        {FILTERS.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                aria-pressed={filter === option.value}
                                onClick={() => setFilter(option.value)}
                                className={cn(
                                    'rounded-control px-2.5 py-0.5 text-[12px] text-muted',
                                    filter === option.value && 'bg-panel font-medium text-body shadow-card',
                                )}
                            >
                                {option.label}
                            </button>
                        ))}
                    </div>
                    <Tooltip label={FILTER_HELP}>
                        <span className="flex text-faint" aria-label={FILTER_HELP}>
                            <CircleHelp size={13} />
                        </span>
                    </Tooltip>
                </div>
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto px-2 pb-3">
                {data === null || (isLoading && isEmpty) ? (
                    <div className="flex flex-col gap-2 px-1 pt-3" data-testid="thread-list-loading">
                        {[0, 1, 2, 3, 4].map((index) => (
                            <Skeleton key={index} className="h-9 w-full" />
                        ))}
                    </div>
                ) : isEmpty ? (
                    <div className="mt-3 rounded-card border border-dashed border-hair p-4 text-center" data-testid="thread-list-empty">
                        <div className="text-[13px] font-medium">No threads yet</div>
                        <p className="mt-1 text-[12px] text-muted">
                            {search !== ''
                                ? 'Nothing matches that search.'
                                : 'Start a thread and it shows up here. Threads from your teammates are under Everyone.'}
                        </p>
                    </div>
                ) : (
                    <>
                        <ThreadGroup groupKey="needs-you" title="Needs you" count={data.needsYouCount} rows={data.needsYou} activeThreadId={activeThreadId} />
                        <ThreadGroup groupKey="today" title="Today" rows={rowsOf('today')} activeThreadId={activeThreadId} />
                        <ThreadGroup groupKey="this-week" title="This week" rows={rowsOf('this_week')} activeThreadId={activeThreadId} />
                        <ThreadGroup groupKey="older" title="Older" rows={rowsOf('older')} activeThreadId={activeThreadId} />
                    </>
                )}
            </div>
        </div>
    );
}
