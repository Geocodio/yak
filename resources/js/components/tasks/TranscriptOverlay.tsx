import { router } from '@inertiajs/react';
import { Badge, Dialog, Kbd, cn } from '@geocodio/console-ui';
import { ChevronRight } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { navigateTaskQuery } from '@/lib/taskQuery';
import type { ActivityRow, RunSummary, TranscriptEntry } from '@/types/tasks';

type Filter = 'All' | 'Actions' | 'Milestones';

function Block({ title, children, error }: { title: string; children?: React.ReactNode; error?: boolean }) {
    return (
        <div className="mt-4">
            <div className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-faint">{title}</div>
            <pre className={cn('overflow-auto rounded-card border border-hair bg-panel-2 p-3 font-mono text-[11.5px] leading-relaxed', error && 'border-fail/30 bg-fail-soft/40 text-fail')}>
                {children}
            </pre>
        </div>
    );
}

export function TranscriptOverlay({
    open,
    onOpenChange,
    rows,
    entry,
    headline,
    runs,
    currentRunId,
    attempts,
    currentAttempt,
    selectedLogId,
    onSelectLog,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    rows: ActivityRow[];
    entry: TranscriptEntry | null | undefined;
    headline: string;
    runs: RunSummary[];
    currentRunId: number;
    attempts: number[];
    currentAttempt: number;
    selectedLogId: number | null;
    onSelectLog: (logId: number) => void;
}) {
    const [filter, setFilter] = useState<Filter>('All');
    const [query, setQuery] = useState('');
    const listRef = useRef<HTMLOListElement>(null);
    const dialogRef = useRef<HTMLDivElement>(null);
    const cacheRef = useRef(new Map<number, TranscriptEntry>());
    const runKey = `${currentRunId}:${currentAttempt}`;
    const runAttemptKeyRef = useRef(runKey);

    const idx = rows.findIndex((row) => row.id === selectedLogId);
    // A deep link to a row older than the loaded window has no match here;
    // `sel` still gives Previous/Next a base to step from (the first loaded
    // row), but the rail must not highlight a row that isn't the open one.
    const sel = idx >= 0 ? idx : 0;
    const selectedId = idx >= 0 ? rows[idx].id : null;
    const outOfWindow = selectedLogId !== null && idx < 0;

    // A cold open (the header button, no `?log=`) has nothing selected yet:
    // pick the first row once so a fetch has something to ask for. A deep
    // link to a row outside the loaded window already has a selected id and
    // must keep it rather than being redirected to row 0.
    useEffect(() => {
        if (open && rows.length > 0 && selectedLogId === null) {
            onSelectLog(rows[0].id);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, rows, selectedLogId]);

    // A run or attempt switch invalidates every cached entry -- they belong
    // to a different transcript. Declared before the fetch effect below so
    // the clear always lands in the same commit before the fetch effect
    // checks the cache for `selectedLogId`.
    useEffect(() => {
        if (runAttemptKeyRef.current !== runKey) {
            runAttemptKeyRef.current = runKey;
            cacheRef.current.clear();
        }
    }, [runKey]);

    // Cache every entry the server sends so stepping back to it never
    // re-fetches, except a tool call still running: its output arrives after
    // the row does, so it is refetched on every visit until output exists.
    useEffect(() => {
        if (entry?.id != null && !(entry.kind === 'tool' && entry.output === null)) {
            cacheRef.current.set(entry.id, entry);
        }
    }, [entry]);

    // Also depends on `runKey` -- a run/attempt switch while the same log id
    // stays selected clears the cache above but wouldn't otherwise re-run
    // this effect, leaving the pane stuck showing the stale entry (or
    // "Loading entry…" forever if nothing was cached for it before).
    useEffect(() => {
        if (!open || selectedLogId === null || cacheRef.current.has(selectedLogId)) {
            return;
        }
        router.reload({ only: ['transcriptEntry'], data: { log: selectedLogId }, preserveUrl: true });
    }, [open, selectedLogId, runKey]);

    const visible = rows.filter(
        (row) =>
            (filter === 'All' || (filter === 'Actions' ? row.kind === 'tool' : row.milestone)) &&
            (!query || row.text.toLowerCase().includes(query.toLowerCase())),
    );

    const current = selectedLogId === null ? undefined : (cacheRef.current.get(selectedLogId) ?? (entry?.id === selectedLogId ? entry : undefined));

    const select = (logId: number) => {
        onSelectLog(logId);
    };

    const step = (offset: number) => {
        if (rows.length === 0) {
            return;
        }
        const next = Math.min(rows.length - 1, Math.max(0, sel + offset));
        select(rows[next].id);
    };

    useEffect(() => {
        if (!open) {
            return;
        }
        const onKey = (event: KeyboardEvent) => {
            const active = document.activeElement as HTMLElement | null;
            if (active && (['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName) || active.isContentEditable)) {
                return;
            }
            // Scope to this overlay's own dialog -- a sibling dialog (a
            // ConfirmDialog, the media lightbox) mounted at the same time
            // must handle its own Escape/arrow keys, not have them
            // swallowed here.
            const dialog = dialogRef.current;
            const target = event.target as Node;
            if (!dialog || !(dialog.contains(active) || dialog.contains(target))) {
                return;
            }
            if (event.key === 'ArrowRight' || event.key === 'j') {
                step(1);
            } else if (event.key === 'ArrowLeft' || event.key === 'k') {
                step(-1);
            } else if (event.key === '/') {
                event.preventDefault();
                document.querySelector<HTMLInputElement>('[data-testid="transcript-search"]')?.focus();
            } else if (event.key === 'Escape') {
                onOpenChange(false);
            }
        };
        document.addEventListener('keydown', onKey, true);
        return () => document.removeEventListener('keydown', onKey, true);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, sel, rows]);

    useEffect(() => {
        if (!open) {
            return;
        }
        // The dialog's open transition can still be running its first paint
        // when this effect fires (Base UI's `data-starting-style`), which
        // sometimes leaves the popup with a zero-size layout at that exact
        // moment -- a `requestAnimationFrame` defers the scroll to after
        // that frame settles.
        const frame = requestAnimationFrame(() => {
            const row = listRef.current?.querySelector('[data-log-selected]');
            row?.scrollIntoView({ block: 'nearest' });
        });
        return () => cancelAnimationFrame(frame);
    }, [open, sel]);

    if (!open) {
        return null;
    }

    return (
        <Dialog
            ref={dialogRef}
            open={open}
            onOpenChange={onOpenChange}
            title="Transcript"
            hideTitle
            width="w-[calc(100vw-2rem)]"
            className="h-[calc(100dvh-2rem)] max-w-none p-0"
            data-testid="transcript-overlay"
        >
            <div className="flex h-full flex-col">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-hair px-4 py-2">
                    <span className="text-[13px] font-semibold">Transcript</span>
                    <span className="truncate text-[12px] text-muted">{headline}</span>
                    {runs.length > 1 &&
                        runs.map((run) => (
                            <Badge key={run.id} tone={run.id === currentRunId ? 'accent' : 'neutral'}>
                                {run.label}
                                {run.live && ' · live'}
                            </Badge>
                        ))}
                    {attempts.length > 1 && (
                        <>
                            <span className="text-faint">·</span>
                            <span className="text-[11px] text-faint">Attempt</span>
                            <div className="flex gap-0.5 rounded-control bg-panel-2 p-0.5">
                                {attempts.map((attempt) => (
                                    <button
                                        key={attempt}
                                        type="button"
                                        onClick={() => navigateTaskQuery({ attempt })}
                                        className={cn('h-5 rounded-chip px-1.5 text-[11px] text-muted', attempt === currentAttempt && 'bg-panel text-body shadow-card')}
                                        data-testid={`attempt-${attempt}`}
                                    >
                                        #{attempt}
                                    </button>
                                ))}
                            </div>
                        </>
                    )}
                    <button
                        type="button"
                        onClick={() => onOpenChange(false)}
                        className="ml-auto flex items-center gap-1.5 rounded-control px-2 py-1 text-[12px] text-muted hover:bg-panel-2 hover:text-body"
                    >
                        Close <Kbd keys={['esc']} />
                    </button>
                </div>
                <div className="grid min-h-0 flex-1 grid-cols-1 md:grid-cols-[340px_1fr]">
                    <div className="flex min-h-0 flex-col overflow-y-auto border-b border-hair bg-sidebar max-md:max-h-[40dvh] md:border-r md:border-b-0">
                        <div className="flex flex-wrap items-center gap-2 gap-y-2 border-b border-hair p-2">
                            <div className="flex gap-0.5 rounded-control bg-panel-2 p-0.5">
                                {(['All', 'Actions', 'Milestones'] as const).map((f) => (
                                    <button
                                        key={f}
                                        type="button"
                                        onClick={() => setFilter(f)}
                                        className={cn('h-6 rounded-chip px-2 text-[11px] text-muted', filter === f && 'bg-panel text-body shadow-card')}
                                    >
                                        {f}
                                    </button>
                                ))}
                            </div>
                            <input
                                data-testid="transcript-search"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                placeholder="Search this run…"
                                className="h-7 flex-1 rounded-control border border-hair bg-panel px-2 text-[12px] outline-none focus:border-accent"
                            />
                        </div>
                        <ol ref={listRef} data-scroller className="min-h-0 flex-1 overflow-auto py-1">
                            {visible.length === 0 && <li className="p-4 text-[12px] text-faint">No entries match &quot;{query}&quot;.</li>}
                            {visible.map((row) => {
                                const isSelected = row.id === selectedId;
                                return (
                                    <li key={row.id}>
                                        <button
                                            type="button"
                                            onClick={() => select(row.id)}
                                            className={cn(
                                                'flex w-full items-start gap-2 px-3 py-1.5 text-left hover:bg-panel-2',
                                                isSelected && 'bg-accent-soft ring-1 ring-inset ring-accent/40',
                                            )}
                                            data-testid={isSelected ? 'log-entry-open' : `transcript-entry-${row.id}`}
                                            {...(isSelected ? { 'data-log-selected': row.id } : {})}
                                        >
                                            {row.badge && (
                                                <span
                                                    className={cn(
                                                        'mt-0.5 shrink-0 rounded-chip px-1 font-mono text-[10px]',
                                                        row.kind === 'prompt' ? 'bg-warn-soft text-warn' : row.error ? 'bg-fail-soft text-fail' : 'bg-panel-2 text-muted',
                                                    )}
                                                >
                                                    {row.badge}
                                                </span>
                                            )}
                                            <span className={cn('min-w-0 flex-1 truncate text-[12px]', row.error && 'text-fail', row.milestone && 'font-medium')}>{row.text}</span>
                                            <span className="tnum shrink-0 text-[10px] text-faint">{row.at.replace(' AM', '').replace(' PM', '')}</span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ol>
                    </div>
                    <div className="flex min-h-0 flex-col">
                        <div className="flex h-10 shrink-0 items-center gap-3 border-b border-hair px-4">
                            <button type="button" onClick={() => step(-1)} className="rounded-control p-1 text-muted hover:bg-panel-2" data-testid="log-prev">
                                <ChevronRight size={13} className="rotate-180" />
                            </button>
                            <span className="tnum text-[12px] text-muted" data-testid="transcript-step">
                                Step {sel + 1} of {rows.length}
                            </span>
                            <button type="button" onClick={() => step(1)} className="rounded-control p-1 text-muted hover:bg-panel-2" data-testid="log-next">
                                <ChevronRight size={13} />
                            </button>
                            <span className="ml-auto flex items-center gap-1.5 text-[11px] text-faint">
                                <Kbd keys={['←', '→']} /> or <Kbd keys={['j', 'k']} /> · <Kbd keys={['/']} /> to search
                            </span>
                        </div>
                        <div className="min-h-0 flex-1 overflow-auto p-4 sm:p-5">
                            {current ? (
                                <>
                                    {outOfWindow && <p className="mb-2 text-[11px] text-faint">This entry is older than the loaded activity</p>}
                                    <h3 className={cn('text-[14px] font-semibold', current.error && 'text-fail')}>{current.text}</h3>
                                    <div className="mt-1 flex items-center gap-2 text-[11px] text-faint">
                                        <span>{current.at}</span>
                                        {current.tool && (
                                            <>
                                                <span>·</span>
                                                <span>
                                                    tool · <span className="font-mono">{current.tool}</span>
                                                </span>
                                            </>
                                        )}
                                        {current.error && (
                                            <>
                                                <span>·</span>
                                                <span className="text-fail">errored</span>
                                            </>
                                        )}
                                    </div>
                                    {current.kind === 'prompt' && current.prompt ? (
                                        <>
                                            <dl className="mt-4 grid grid-cols-2 gap-3 text-[11px] md:grid-cols-4">
                                                {Object.entries(current.prompt.meta).map(([k, v]) => (
                                                    <div key={k} className="rounded-card border border-hair bg-panel px-3 py-2">
                                                        <dt className="font-mono text-faint">{k}</dt>
                                                        <dd className="mt-0.5 font-mono">{v}</dd>
                                                    </div>
                                                ))}
                                            </dl>
                                            <Block title="User prompt">{current.prompt.user}</Block>
                                            <Block title="System prompt">{current.prompt.system}</Block>
                                        </>
                                    ) : current.kind === 'assistant' ? (
                                        <div className="mt-4 rounded-card border border-hair bg-panel p-4 text-[13px] leading-relaxed">{current.text}</div>
                                    ) : (
                                        <>
                                            <Block title={current.tool === 'Bash' ? 'Command' : 'Input'}>{current.input}</Block>
                                            <Block title="Output" error={current.error}>
                                                {current.output}
                                            </Block>
                                        </>
                                    )}
                                </>
                            ) : (
                                <p className="text-[12px] text-faint">Loading entry…</p>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </Dialog>
    );
}
