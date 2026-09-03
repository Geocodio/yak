import { Badge, IconButton, Tooltip, cn } from '@geocodio/console-ui';
import { ChevronDown, Expand } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { navigateTaskQuery } from '@/lib/taskQuery';
import type { ActivityData, ActivityRow, RunSummary } from '@/types/tasks';

type Filter = 'all' | 'actions' | 'milestones';

type DisplayItem = { type: 'row'; row: ActivityRow } | { type: 'group'; groupIndex: number; rows: ActivityRow[] };

function buildDisplayItems(rows: ActivityRow[], grouped: boolean): DisplayItem[] {
    if (!grouped) {
        return rows.map((row) => ({ type: 'row', row }));
    }

    const items: DisplayItem[] = [];
    let i = 0;
    while (i < rows.length) {
        const row = rows[i];
        if (row.group !== null) {
            const groupRows = [row];
            let j = i + 1;
            while (j < rows.length && rows[j].group === row.group) {
                groupRows.push(rows[j]);
                j++;
            }
            items.push({ type: 'group', groupIndex: row.group, rows: groupRows });
            i = j;
        } else {
            items.push({ type: 'row', row });
            i++;
        }
    }
    return items;
}

export function ActivityLog({
    taskId,
    activity,
    runs,
    currentRunId,
    attempts,
    currentAttempt,
    openLogId,
    onOpenTranscript,
    onOpenTranscriptCold,
}: {
    taskId: number;
    activity: ActivityData;
    runs: RunSummary[];
    currentRunId: number;
    attempts: number[];
    currentAttempt: number;
    openLogId: number | null;
    onOpenTranscript: (logId: number) => void;
    onOpenTranscriptCold: () => void;
}) {
    const [filter, setFilter] = useState<Filter>('all');
    const [search, setSearch] = useState('');
    const [expandedGroups, setExpandedGroups] = useState<Set<number>>(new Set());
    const scrollRef = useRef<HTMLDivElement>(null);
    const [following, setFollowing] = useState(true);

    const filteredRows = useMemo(() => {
        return activity.rows.filter((row) => {
            if (filter === 'actions' && row.kind !== 'tool') {
                return false;
            }
            if (filter === 'milestones' && !row.milestone) {
                return false;
            }
            if (search.trim() !== '' && !row.text.toLowerCase().includes(search.trim().toLowerCase())) {
                return false;
            }
            return true;
        });
    }, [activity.rows, filter, search]);

    const grouped = filter === 'all' && search.trim() === '';
    const displayItems = useMemo(() => buildDisplayItems(filteredRows, grouped), [filteredRows, grouped]);

    useEffect(() => {
        const el = scrollRef.current;
        if (!el) {
            return;
        }
        const onScroll = () => {
            const distanceFromBottom = el.scrollHeight - el.scrollTop - el.clientHeight;
            setFollowing(distanceFromBottom < 48);
        };
        el.addEventListener('scroll', onScroll, { passive: true });
        return () => el.removeEventListener('scroll', onScroll);
    }, []);

    useEffect(() => {
        const el = scrollRef.current;
        if (!el || !following) {
            return;
        }
        el.scrollTop = el.scrollHeight;
    }, [activity.rows, following]);

    const jumpToLatest = () => {
        const el = scrollRef.current;
        if (!el) {
            return;
        }
        el.scrollTop = el.scrollHeight;
        setFollowing(true);
    };

    const toggleGroup = (index: number) => {
        setExpandedGroups((prev) => {
            const next = new Set(prev);
            if (next.has(index)) {
                next.delete(index);
            } else {
                next.add(index);
            }
            return next;
        });
    };

    return (
        <section className="flex min-h-0 flex-col" data-testid="activity-log">
            <div className="mb-2 flex items-center justify-between">
                <h2 className="text-[11px] font-semibold uppercase tracking-wide text-faint">Activity</h2>
                <div className="flex items-center gap-1">
                    <span className="tnum text-[11px] text-faint">
                        {activity.entries} entries · {activity.duration}
                    </span>
                    <Tooltip label="Open the full transcript">
                        <IconButton label="Open the full transcript" onClick={onOpenTranscriptCold} className="h-6 w-6 border-0 bg-transparent shadow-none" data-testid="open-transcript">
                            <Expand size={12} />
                        </IconButton>
                    </Tooltip>
                </div>
            </div>

            {runs.length > 1 && (
                <div className="mb-2 flex flex-wrap items-center gap-1" data-testid="run-picker">
                    {runs.map((run) => (
                        <button
                            key={run.id}
                            type="button"
                            onClick={() => navigateTaskQuery({ run: run.id, attempt: undefined })}
                            data-testid={`run-chip-${run.id}`}
                        >
                            <Badge tone={run.id === currentRunId ? 'accent' : 'neutral'}>
                                {run.label}
                                {run.live && ' · live'}
                            </Badge>
                        </button>
                    ))}
                </div>
            )}

            {attempts.length > 1 && (
                <div className="mb-2 flex flex-wrap items-center gap-2" data-testid="attempt-selector">
                    <span className="text-[11px] text-faint">Attempt</span>
                    <div className="flex gap-0.5 rounded-control bg-panel-2 p-0.5">
                        {attempts.map((attempt) => (
                            <button
                                key={attempt}
                                type="button"
                                onClick={() => navigateTaskQuery({ attempt })}
                                className={cn(
                                    'h-5 rounded-chip px-1.5 text-[11px] text-muted',
                                    attempt === currentAttempt && 'bg-panel text-body shadow-card',
                                )}
                                data-testid={`attempt-${attempt}`}
                            >
                                #{attempt}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            <div className="mb-2 flex items-center gap-2">
                <div className="flex gap-0.5 rounded-control bg-panel-2 p-0.5" data-testid="log-filter">
                    {(['all', 'actions', 'milestones'] as const).map((f) => (
                        <button
                            key={f}
                            type="button"
                            onClick={() => setFilter(f)}
                            className={cn('h-5 rounded-chip px-1.5 text-[11px] capitalize text-muted', filter === f && 'bg-panel text-body shadow-card')}
                        >
                            {f}
                        </button>
                    ))}
                </div>
                <input
                    type="text"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Search…"
                    className="h-6 flex-1 rounded-control border border-hair bg-panel px-2 text-[11px] outline-none focus:border-accent"
                    data-testid="log-search"
                />
            </div>

            <div className="relative">
                <div ref={scrollRef} data-scroller className="max-h-[420px] overflow-y-auto rounded-card border border-hair bg-panel shadow-card">
                    {displayItems.length === 0 && <p className="px-3 py-6 text-center text-[12px] text-faint">No entries match &ldquo;{search}&rdquo;.</p>}
                    {displayItems.map((item) =>
                        item.type === 'group' ? (
                            <div key={`group-${item.groupIndex}`} className="border-b border-hair last:border-0">
                                <button
                                    type="button"
                                    onClick={() => toggleGroup(item.groupIndex)}
                                    className="flex w-full items-center gap-2 px-2.5 py-1.5 text-left hover:bg-panel-2"
                                >
                                    <ChevronDown size={11} className={cn('shrink-0 text-faint transition-transform', !expandedGroups.has(item.groupIndex) && '-rotate-90')} />
                                    <span className="shrink-0 rounded-chip bg-panel-2 px-1 font-mono text-[10px] text-muted">
                                        {item.rows.length} thinking {item.rows.length === 1 ? 'step' : 'steps'}
                                    </span>
                                    <span className="min-w-0 flex-1 truncate text-[12px] text-muted">{item.rows[item.rows.length - 1].text}</span>
                                </button>
                                {expandedGroups.has(item.groupIndex) && (
                                    <div className="bg-panel-2/40">
                                        {item.rows.map((row) => (
                                            <div key={row.id} className="border-t border-hair px-3 py-1.5 text-[12px] italic text-muted">
                                                {row.text}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        ) : (
                            <button
                                key={item.row.id}
                                type="button"
                                onClick={() => onOpenTranscript(item.row.id)}
                                className={cn(
                                    'flex w-full items-start gap-2 border-b border-hair px-2.5 py-1.5 text-left last:border-0 hover:bg-panel-2',
                                    item.row.milestone && 'bg-accent-soft/40',
                                )}
                                data-testid={item.row.id === openLogId ? 'log-entry-open' : item.row.milestone ? 'milestone-log' : 'log-entry'}
                            >
                                {item.row.badge && (
                                    <span
                                        className={cn(
                                            'mt-0.5 shrink-0 rounded-chip px-1 font-mono text-[10px]',
                                            item.row.error ? 'bg-fail-soft text-fail' : item.row.milestone ? 'bg-accent-soft text-accent-text' : 'bg-panel-2 text-muted',
                                        )}
                                    >
                                        {item.row.badge}
                                    </span>
                                )}
                                <span className={cn('min-w-0 flex-1 truncate text-[12px]', item.row.error && 'text-fail', item.row.milestone && 'font-medium')}>
                                    {item.row.text}
                                </span>
                                <span className="tnum shrink-0 text-[10px] text-faint">{item.row.at}</span>
                            </button>
                        ),
                    )}
                </div>

                {!following && (
                    <button
                        type="button"
                        onClick={jumpToLatest}
                        className="absolute bottom-3 right-3 flex items-center gap-1.5 rounded-pill bg-accent px-2.5 py-1 text-[11px] text-accent-ink shadow-card"
                        data-testid="jump-to-latest"
                    >
                        <ChevronDown size={11} /> Jump to latest
                    </button>
                )}
            </div>
        </section>
    );
}
