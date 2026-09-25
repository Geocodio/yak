import { Badge, IconButton, Tooltip, cn } from '@geocodio/console-ui';
import { ChevronDown, Expand } from 'lucide-react';
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { navigateTaskQuery } from '@/lib/taskQuery';
import type { ActivityRow, RunSummary } from '@/types/tasks';

type Filter = 'all' | 'actions' | 'milestones';

type DisplayItem = { type: 'row'; row: ActivityRow } | { type: 'group'; groupIndex: number; rows: ActivityRow[] };

function isGroupable(row: ActivityRow): boolean {
    return row.kind === 'assistant' && !row.milestone;
}

function buildDisplayItems(rows: ActivityRow[], grouped: boolean): DisplayItem[] {
    if (!grouped) {
        return rows.map((row) => ({ type: 'row', row }));
    }

    const items: DisplayItem[] = [];
    let i = 0;
    let groupIndex = 0;
    while (i < rows.length) {
        const row = rows[i];
        if (isGroupable(row)) {
            const groupRows = [row];
            let j = i + 1;
            while (j < rows.length && isGroupable(rows[j])) {
                groupRows.push(rows[j]);
                j++;
            }
            items.push({ type: 'group', groupIndex: groupIndex++, rows: groupRows });
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
    rows,
    hasOlder,
    loadingOlder,
    capped,
    onLoadOlder,
    entries,
    duration,
    runs,
    currentRunId,
    attempts,
    currentAttempt,
    openLogId,
    onOpenTranscript,
    onOpenTranscriptCold,
    fill = false,
}: {
    taskId: number;
    rows: ActivityRow[];
    entries: number;
    duration: string;
    hasOlder: boolean;
    loadingOlder: boolean;
    capped: boolean;
    onLoadOlder: () => void;
    runs: RunSummary[];
    currentRunId: number;
    attempts: number[];
    currentAttempt: number;
    openLogId: number | null;
    onOpenTranscript: (logId: number) => void;
    onOpenTranscriptCold: () => void;
    fill?: boolean;
}) {
    const [filter, setFilter] = useState<Filter>('all');
    const [search, setSearch] = useState('');
    const [expandedGroups, setExpandedGroups] = useState<Set<number>>(new Set());
    const scrollRef = useRef<HTMLDivElement>(null);
    const followingRef = useRef(true);
    const [following, setFollowing] = useState(true);
    const pendingPrependRef = useRef<{ anchorId: number; offsetFromTop: number } | null>(null);

    const filteredRows = useMemo(() => {
        return rows.filter((row) => {
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
    }, [rows, filter, search]);

    const grouped = filter === 'all' && search.trim() === '';
    const displayItems = useMemo(() => buildDisplayItems(filteredRows, grouped), [filteredRows, grouped]);

    useEffect(() => {
        const element = scrollRef.current;
        if (!element) {
            return;
        }
        const onScroll = () => {
            const distanceFromBottom = element.scrollHeight - element.scrollTop - element.clientHeight;
            const nowFollowing = distanceFromBottom < 48;
            if (nowFollowing !== followingRef.current) {
                followingRef.current = nowFollowing;
                setFollowing(nowFollowing);
            }
            if (element.scrollTop < 400 && hasOlder && !loadingOlder && !capped && rows.length > 0) {
                const anchorId = rows[0].id;
                const anchorElement = element.querySelector<HTMLElement>(`[data-log-id="${anchorId}"]`);
                // getBoundingClientRect forces layout of this one row even
                // though content-visibility would otherwise skip it, so the
                // offset is accurate before the older rows are prepended.
                const offsetFromTop = anchorElement ? anchorElement.getBoundingClientRect().top - element.getBoundingClientRect().top : 0;
                pendingPrependRef.current = { anchorId, offsetFromTop };
                onLoadOlder();
            }
        };
        element.addEventListener('scroll', onScroll, { passive: true });
        return () => element.removeEventListener('scroll', onScroll);
    }, [hasOlder, loadingOlder, capped, onLoadOlder, rows]);

    // After older rows are prepended, keep the row the reader was looking at
    // where it was: find that same row by id and shift scrollTop so it sits
    // at the same offset from the scroller's top edge it had before. The
    // rows above it use content-visibility, whose skipped elements only
    // settle on their real size a few frames after insertion, so the
    // correction is re-applied across a handful of frames until it stops
    // moving rather than once synchronously.
    useLayoutEffect(() => {
        const element = scrollRef.current;
        if (!element || !pendingPrependRef.current) {
            return;
        }
        let cancelled = false;
        let frame = 0;
        const settle = () => {
            if (cancelled) {
                return;
            }
            const pending = pendingPrependRef.current;
            const anchorElement = pending ? element.querySelector<HTMLElement>(`[data-log-id="${pending.anchorId}"]`) : null;
            if (pending && anchorElement) {
                const currentOffsetFromTop = anchorElement.getBoundingClientRect().top - element.getBoundingClientRect().top;
                if (currentOffsetFromTop !== pending.offsetFromTop) {
                    element.scrollTop += currentOffsetFromTop - pending.offsetFromTop;
                }
            }
            frame += 1;
            if (frame < 8) {
                requestAnimationFrame(settle);
            } else {
                pendingPrependRef.current = null;
            }
        };
        settle();
        return () => {
            cancelled = true;
        };
    }, [rows]);

    useEffect(() => {
        const element = scrollRef.current;
        if (!element || !followingRef.current) {
            return;
        }
        element.scrollTop = element.scrollHeight;
    }, [rows]);

    const jumpToLatest = () => {
        const element = scrollRef.current;
        if (!element) {
            return;
        }
        element.scrollTop = element.scrollHeight;
        followingRef.current = true;
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
        <section className={cn('flex flex-col', fill ? 'min-h-0 flex-1' : 'shrink-0')} data-testid="activity-log">
            <div className={cn('relative overflow-hidden rounded-card border border-hair bg-panel shadow-card', fill && 'flex min-h-0 flex-1 flex-col')}>
                <div className="flex flex-col gap-2 border-b border-hair bg-panel-2/40 px-2 py-2">
                    <div className="flex items-center justify-between pl-0.5">
                        <h2 className="text-[11px] font-semibold uppercase tracking-wide text-faint">Activity</h2>
                        <div className="flex items-center gap-1">
                            <span className="tnum text-[11px] text-faint">
                                {entries} entries · {duration}
                            </span>
                            <Tooltip label="Open the full transcript">
                                <IconButton label="Open the full transcript" onClick={onOpenTranscriptCold} className="h-6 w-6 border-0 bg-transparent shadow-none" data-testid="open-transcript">
                                    <Expand size={12} />
                                </IconButton>
                            </Tooltip>
                        </div>
                    </div>

                    {runs.length > 1 && (
                        <div className="flex flex-wrap items-center gap-1" data-testid="run-picker">
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
                        <div className="flex flex-wrap items-center gap-2" data-testid="attempt-selector">
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

                    <div className="flex items-center gap-2">
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
                    {search.trim() !== '' && rows.length < entries && (
                        <p className="text-[10px] text-faint">Searching the {rows.length} loaded entries</p>
                    )}
                </div>

                <div ref={scrollRef} data-scroller className={cn('overflow-y-auto', fill ? 'min-h-0 flex-1' : 'max-h-[420px]')}>
                    {capped && hasOlder && (
                        <div className="flex justify-center border-b border-hair px-2.5 py-1.5">
                            <button
                                type="button"
                                onClick={onLoadOlder}
                                disabled={loadingOlder}
                                className="rounded-control bg-panel-2 px-2 py-1 text-[11px] text-muted hover:bg-panel-2/70 disabled:opacity-60"
                            >
                                Load 200 older
                            </button>
                        </div>
                    )}
                    {loadingOlder && <p className="px-2.5 py-1 text-center text-[10px] text-faint">Loading older…</p>}
                    {displayItems.length === 0 && <p className="px-3 py-6 text-center text-[12px] text-faint">No entries match &ldquo;{search}&rdquo;.</p>}
                    {displayItems.map((item) =>
                        item.type === 'group' ? (
                            <div key={`group-${item.groupIndex}`} className="border-b border-hair last:border-0 [content-visibility:auto] [contain-intrinsic-size:auto_44px]">
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
                                    'flex w-full items-start gap-2 border-b border-hair px-2.5 py-1.5 text-left last:border-0 hover:bg-panel-2 min-h-11 lg:min-h-0 [content-visibility:auto] [contain-intrinsic-size:auto_44px]',
                                    item.row.milestone && 'bg-accent-soft/40',
                                )}
                                data-testid={item.row.id === openLogId ? 'log-entry-open' : item.row.milestone ? 'milestone-log' : 'log-entry'}
                                data-log-id={item.row.id}
                                data-log-text={item.row.text}
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
