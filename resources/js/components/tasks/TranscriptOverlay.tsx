import { router } from '@inertiajs/react';
import { Badge, Dialog, Kbd, cn } from '@geocodio/console-ui';
import { ChevronRight } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { navigateTaskQuery, replaceTaskQuery } from '@/lib/taskQuery';
import type { RunSummary, TranscriptEntry } from '@/types/tasks';

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
    entries,
    headline,
    runs,
    currentRunId,
    attempts,
    currentAttempt,
    selectedLogId,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    entries: TranscriptEntry[] | undefined;
    headline: string;
    runs: RunSummary[];
    currentRunId: number;
    attempts: number[];
    currentAttempt: number;
    selectedLogId: number | null;
}) {
    const [filter, setFilter] = useState<Filter>('All');
    const [query, setQuery] = useState('');
    const listRef = useRef<HTMLOListElement>(null);
    const requestedRef = useRef(false);

    const list = entries ?? [];

    const initialIndex = useMemo(() => {
        if (selectedLogId === null) {
            return 0;
        }
        const idx = list.findIndex((entry) => entry.id === selectedLogId);
        return idx >= 0 ? idx : 0;
    }, [selectedLogId, list]);

    const [sel, setSel] = useState(initialIndex);

    useEffect(() => {
        setSel(initialIndex);
    }, [initialIndex]);

    useEffect(() => {
        if (open && entries === undefined && !requestedRef.current) {
            requestedRef.current = true;
            router.reload({ only: ['transcript'] });
        }
        if (!open) {
            requestedRef.current = false;
        }
    }, [open, entries]);

    const visible = list.filter(
        (entry) =>
            (filter === 'All' || (filter === 'Actions' ? entry.kind === 'tool' : entry.milestone)) &&
            (!query || entry.text.toLowerCase().includes(query.toLowerCase())),
    );

    const current = list[sel];

    const select = (index: number) => {
        setSel(index);
        const entry = list[index];
        if (entry) {
            replaceTaskQuery({ log: entry.id });
        }
    };

    useEffect(() => {
        if (!open) {
            return;
        }
        const onKey = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement;
            if (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA') {
                if (event.key === 'Escape') {
                    onOpenChange(false);
                }
                return;
            }
            if (event.key === 'ArrowRight' || event.key === 'j') {
                select(Math.min(list.length - 1, sel + 1));
            } else if (event.key === 'ArrowLeft' || event.key === 'k') {
                select(Math.max(0, sel - 1));
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
    }, [open, sel, list]);

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
            open={open}
            onOpenChange={onOpenChange}
            title="Transcript"
            hideTitle
            width="w-[calc(100vw-2rem)]"
            className="h-[calc(100dvh-2rem)] max-w-none p-0"
            data-testid="transcript-overlay"
        >
            <div className="flex h-full flex-col">
                <div className="flex h-11 shrink-0 items-center gap-3 border-b border-hair px-4">
                    <span className="text-[13px] font-semibold">Transcript</span>
                    <span className="truncate text-[12px] text-muted">{headline}</span>
                    <div className="ml-auto flex items-center gap-2">
                        {runs.length > 1 &&
                            runs.map((run) => (
                                <Badge key={run.id} tone={run.id === currentRunId ? 'accent' : 'neutral'}>
                                    {run.label}
                                    {run.live && ' · live'}
                                </Badge>
                            ))}
                        {attempts.length > 1 && (
                            <>
                                <span className="mx-1 text-faint">·</span>
                                <span className="text-[11px] text-faint">Attempt</span>
                                <div className="flex gap-0.5 rounded-control bg-panel-2 p-0.5">
                                    {attempts.map((attempt) => (
                                        <button
                                            key={attempt}
                                            type="button"
                                            onClick={() => navigateTaskQuery({ attempt })}
                                            className={cn('h-5 rounded-chip px-1.5 text-[11px] text-muted', attempt === currentAttempt && 'bg-panel text-body shadow-card')}
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
                            className="ml-3 flex items-center gap-1.5 rounded-control px-2 py-1 text-[12px] text-muted hover:bg-panel-2 hover:text-body"
                        >
                            Close <Kbd keys={['esc']} />
                        </button>
                    </div>
                </div>
                <div className="grid min-h-0 flex-1 grid-cols-[340px_1fr]">
                    <div className="flex min-h-0 flex-col border-r border-hair bg-sidebar">
                        <div className="flex items-center gap-2 border-b border-hair p-2">
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
                            {visible.map((entry) => {
                                const index = list.indexOf(entry);
                                const isSelected = index === sel;
                                return (
                                    <li key={entry.id}>
                                        <button
                                            type="button"
                                            onClick={() => select(index)}
                                            className={cn(
                                                'flex w-full items-start gap-2 px-3 py-1.5 text-left hover:bg-panel-2',
                                                isSelected && 'bg-accent-soft ring-1 ring-inset ring-accent/40',
                                            )}
                                            data-testid={isSelected ? 'log-entry-open' : `transcript-entry-${entry.id}`}
                                            {...(isSelected ? { 'data-log-selected': entry.id } : {})}
                                        >
                                            {entry.badge && (
                                                <span
                                                    className={cn(
                                                        'mt-0.5 shrink-0 rounded-chip px-1 font-mono text-[10px]',
                                                        entry.kind === 'prompt' ? 'bg-warn-soft text-warn' : entry.error ? 'bg-fail-soft text-fail' : 'bg-panel-2 text-muted',
                                                    )}
                                                >
                                                    {entry.badge}
                                                </span>
                                            )}
                                            <span className={cn('min-w-0 flex-1 truncate text-[12px]', entry.error && 'text-fail', entry.milestone && 'font-medium')}>{entry.text}</span>
                                            <span className="tnum shrink-0 text-[10px] text-faint">{entry.at.replace(' AM', '').replace(' PM', '')}</span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ol>
                    </div>
                    <div className="flex min-h-0 flex-col">
                        <div className="flex h-10 shrink-0 items-center gap-3 border-b border-hair px-4">
                            <button type="button" onClick={() => select(Math.max(0, sel - 1))} className="rounded-control p-1 text-muted hover:bg-panel-2" data-testid="log-prev">
                                <ChevronRight size={13} className="rotate-180" />
                            </button>
                            <span className="tnum text-[12px] text-muted" data-testid="transcript-step">
                                Step {sel + 1} of {list.length}
                            </span>
                            <button
                                type="button"
                                onClick={() => select(Math.min(list.length - 1, sel + 1))}
                                className="rounded-control p-1 text-muted hover:bg-panel-2"
                                data-testid="log-next"
                            >
                                <ChevronRight size={13} />
                            </button>
                            <span className="ml-auto flex items-center gap-1.5 text-[11px] text-faint">
                                <Kbd keys={['←', '→']} /> or <Kbd keys={['j', 'k']} /> · <Kbd keys={['/']} /> to search
                            </span>
                        </div>
                        <div className="min-h-0 flex-1 overflow-auto p-5">
                            {current ? (
                                <>
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
                                            <dl className="mt-4 grid grid-cols-4 gap-3 text-[11px]">
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
                                <p className="text-[12px] text-faint">Loading transcript…</p>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </Dialog>
    );
}
