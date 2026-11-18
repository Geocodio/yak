import { Badge, Button, Dialog, Skeleton, cn, toast } from '@geocodio/console-ui';
import { Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Prose } from '@/components/Prose';
import { useTranscriptEntry } from '@/components/shared/useTranscriptEntry';
import { formatMs } from '@/lib/format';
import type { ActivityRow, TranscriptEntry } from '@/types/tasks';

/** What the list needs of a step; an activity row and a loaded transcript entry both fit. */
export type TranscriptRow = Pick<ActivityRow, 'id' | 'badge' | 'text' | 'at' | 'kind' | 'error' | 'milestone'> & Partial<Pick<TranscriptEntry, 'tool' | 'subject' | 'number'>>;

/** Loading, empty and error states, and paging, for a log that is fetched in pages instead of read from the page props. */
export type TranscriptPaging = {
    status: 'loading' | 'ready' | 'error';
    onRetry: () => void;
    emptyText: string;
    hasMore: boolean;
    isLoadingMore: boolean;
    /** The last request for a further page failed; the loaded steps stay and Retry asks for that page again. */
    moreFailed: boolean;
    onLoadMore: () => void;
    /** Searching asks the server, so the list is not filtered here. */
    onSearch: (query: string) => void;
};

const TOOL_KINDS: Record<string, string> = { Read: 'Read', Grep: 'Search', Glob: 'Search', Bash: 'Run', Edit: 'Edit', MultiEdit: 'Edit', Write: 'Edit', NotebookEdit: 'Edit', WebFetch: 'Fetch', WebSearch: 'Search', Task: 'Agent' };

/** The short kind a step is listed under: Read, Search, Think, Run, Edit and so on. */
export function stepKind(row: Pick<TranscriptRow, 'kind' | 'badge' | 'tool'>): string {
    if (row.kind === 'tool') {
        return TOOL_KINDS[row.tool ?? ''] ?? row.tool ?? 'Tool';
    }
    const named: Record<string, string> = { thinking: 'Think', prompt: 'Prompt', result: 'Result', error: 'Error', system: 'Start', output: 'Output' };

    return named[row.badge ?? ''] ?? (row.kind === 'assistant' ? 'Reply' : (row.badge ?? 'Step'));
}

/** `9:58:03 AM` as `9:58 AM`. */
const clock = (at: string) => at.replace(/:\d{2}(\s?[AP]M)$/, '$1');

function Block({ title, children, error }: { title: string; children?: React.ReactNode; error?: boolean }) {
    return (
        <div className="mt-4">
            <div className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-faint">{title}</div>
            {children === null || children === undefined || children === '' ? (
                <p className="text-[12px] text-faint">No {title.toLowerCase()} recorded.</p>
            ) : (
                <pre className={cn('overflow-auto whitespace-pre-wrap rounded-card border border-hair bg-panel-2 p-3 font-mono [overflow-wrap:anywhere] text-[11.5px] leading-relaxed', error && 'border-fail/30 bg-fail-soft/40 text-fail')}>
                    {children}
                </pre>
            )}
        </div>
    );
}

export function TranscriptOverlay({
    open,
    onOpenChange,
    rows,
    entry,
    runLine,
    onPreviousRun,
    onNextRun,
    runKey,
    selectedLogId,
    onSelectLog,
    linkFor,
    paging,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    rows: TranscriptRow[];
    entry: TranscriptEntry | null | undefined;
    /** What follows the title: "Run 1 of 2 · 9:52 AM · 38 steps · 14m · Sonnet 5.5". */
    runLine: string;
    /** The run buttons are hidden when both are undefined; null disables one at the end of the runs. */
    onPreviousRun?: (() => void) | null;
    onNextRun?: (() => void) | null;
    runKey: string;
    selectedLogId: number | null;
    onSelectLog: (logId: number) => void;
    /** The address that opens this log at a step; the copy button is hidden without it. */
    linkFor?: (logId: number) => string;
    paging?: TranscriptPaging;
}) {
    const [query, setQuery] = useState('');
    const [isCopied, setIsCopied] = useState(false);
    const [advanceFrom, setAdvanceFrom] = useState<number | null>(null);
    const listRef = useRef<HTMLOListElement>(null);
    const dialogRef = useRef<HTMLDivElement>(null);
    const searchRef = useRef<HTMLInputElement>(null);
    const closeRef = useRef<HTMLButtonElement>(null);
    const queryRef = useRef(query);
    queryRef.current = query;
    const hasRunButtons = onPreviousRun !== undefined || onNextRun !== undefined;

    const idx = rows.findIndex((row) => row.id === selectedLogId);
    // A deep link to a row older than the loaded window has no match here;
    // `sel` still gives Previous/Next a base to step from (the first loaded
    // row), but the list must not mark a row that isn't the open one.
    const sel = idx >= 0 ? idx : 0;
    const selectedId = idx >= 0 ? rows[idx].id : null;
    const outOfWindow = selectedLogId !== null && idx < 0;
    const hasNextStep = rows.length > 0 && (idx < 0 || sel < rows.length - 1 || paging?.hasMore === true);

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

    // Next at the end of the loaded steps asks for the next page and moves to its first step once it arrives.
    useEffect(() => {
        if (advanceFrom !== null && rows.length > advanceFrom) {
            onSelectLog(rows[advanceFrom].id);
            setAdvanceFrom(null);
        } else if (advanceFrom !== null && paging?.moreFailed) {
            setAdvanceFrom(null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [advanceFrom, rows.length, paging?.moreFailed]);

    // Another run starts with a clean search.
    useEffect(() => {
        setQuery('');
        setAdvanceFrom(null);
    }, [runKey]);

    const current = useTranscriptEntry({ open, selectedLogId, entry, runKey, fetchesEntry: paging === undefined });

    const step = (offset: number) => {
        if (rows.length === 0) {
            return;
        }
        // The open step is not in the list (a search dropped it): either direction goes to the first result.
        if (idx < 0) {
            onSelectLog(rows[0].id);

            return;
        }
        if (offset > 0 && sel >= rows.length - 1) {
            if (paging?.hasMore) {
                setAdvanceFrom(rows.length);
                paging.onLoadMore();
            }

            return;
        }
        onSelectLog(rows[Math.min(rows.length - 1, Math.max(0, sel + offset))].id);
    };

    const changeQuery = (value: string) => {
        setQuery(value);
        paging?.onSearch(value);
    };

    const copyLink = () => {
        if (linkFor === undefined || current === undefined) {
            return;
        }
        navigator.clipboard.writeText(linkFor(current.id)).then(
            () => {
                setIsCopied(true);
                toast.success('Link to this step copied.');
                window.setTimeout(() => setIsCopied(false), 2000);
            },
            () => toast.error('Could not copy the link.'),
        );
    };

    useEffect(() => {
        if (!open) {
            return;
        }
        const onKey = (event: KeyboardEvent) => {
            const active = document.activeElement as HTMLElement | null;
            // Scope to this overlay's own dialog -- a sibling dialog (a
            // ConfirmDialog, the media lightbox) mounted at the same time
            // must handle its own Escape/arrow keys, not have them
            // swallowed here.
            const dialog = dialogRef.current;
            const target = event.target as Node;
            if (!dialog || !(dialog.contains(active) || dialog.contains(target))) {
                return;
            }
            const isTyping = active !== null && (['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName) || active.isContentEditable);
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'f') {
                event.preventDefault();
                searchRef.current?.focus();
                searchRef.current?.select();
            } else if (event.key === 'Escape' && active === searchRef.current && queryRef.current !== '') {
                event.preventDefault();
                event.stopImmediatePropagation();
                changeQuery('');
            } else if (isTyping) {
                return;
            } else if (event.key === 'ArrowRight' || event.key === 'j') {
                step(1);
            } else if (event.key === 'ArrowLeft' || event.key === 'k') {
                step(-1);
            } else if (event.key === '/') {
                event.preventDefault();
                searchRef.current?.focus();
            } else if (event.key === 'Escape') {
                onOpenChange(false);
            }
        };
        document.addEventListener('keydown', onKey, true);
        return () => document.removeEventListener('keydown', onKey, true);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, sel, idx, rows, paging?.hasMore]);

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

    const isSearching = query.trim() !== '';
    const isReady = paging === undefined || paging.status === 'ready';
    const stepNumber = (row: TranscriptRow, index: number) => row.number ?? index + 1;
    const currentIndex = current === undefined ? -1 : rows.findIndex((row) => row.id === current.id);
    const currentNumber = current === undefined ? null : (current.number ?? (currentIndex >= 0 ? currentIndex + 1 : null));
    const isRunning = current?.kind === 'tool' && current.output == null;
    const pill = current === undefined ? null : current.error ? { tone: 'fail' as const, label: 'Failed' } : current.kind !== 'tool' ? null : isRunning ? { tone: 'neutral' as const, label: 'No output yet' } : { tone: 'ok' as const, label: 'Done' };

    return (
        <Dialog
            ref={dialogRef}
            open={open}
            onOpenChange={onOpenChange}
            title="Full log"
            hideTitle
            width="w-[min(1180px,calc(100vw-2rem))]"
            className="h-[min(760px,calc(100dvh-2rem))] max-w-none p-0"
            initialFocus={closeRef}
            data-testid="transcript-overlay"
        >
            <div className="flex h-full flex-col">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-hair px-[18px] py-2.5">
                    <h2 className="text-[15px] font-semibold">Full log</h2>
                    <span className="min-w-0 truncate text-[12px] text-muted" data-testid="transcript-run-line">
                        {runLine}
                    </span>
                    <span className="flex-1" />
                    <label className="relative block w-[260px]">
                        <span className="sr-only">Search this run</span>
                        <Search size={14} className="pointer-events-none absolute left-2.5 top-2.5 text-faint" aria-hidden />
                        <input
                            ref={searchRef}
                            data-testid="transcript-search"
                            type="text"
                            value={query}
                            onChange={(event) => changeQuery(event.target.value)}
                            placeholder="Search this run"
                            autoComplete="off"
                            className="h-8 w-full rounded-control border border-hair bg-panel pl-8 pr-8 text-[13px] outline-none focus:border-accent"
                        />
                        {isSearching && (
                            <button type="button" onClick={() => { changeQuery(''); searchRef.current?.focus(); }} aria-label="Clear search" className="absolute right-1.5 top-1.5 rounded-control p-1 text-faint hover:text-body" data-testid="transcript-search-clear">
                                <X size={13} />
                            </button>
                        )}
                    </label>
                    {hasRunButtons && (
                        <>
                            <Button variant="secondary" className="h-8" onClick={onPreviousRun ?? undefined} disabled={!onPreviousRun} data-testid="run-previous">
                                Previous run
                            </Button>
                            <Button variant="secondary" className="h-8" onClick={onNextRun ?? undefined} disabled={!onNextRun} data-testid="run-next">
                                Next run
                            </Button>
                        </>
                    )}
                    <Button ref={closeRef} variant="secondary" className="h-8" onClick={() => onOpenChange(false)} aria-label="Close" data-testid="transcript-close">
                        Close
                    </Button>
                </div>
                <div className="grid min-h-0 flex-1 grid-cols-[minmax(0,1fr)] md:grid-cols-[440px_minmax(0,1fr)]">
                    <div className="flex min-h-0 flex-col border-b border-hair bg-sidebar max-md:max-h-[40dvh] md:border-r md:border-b-0">
                        {isSearching && isReady && (
                            <div className="shrink-0 border-b border-hair px-3.5 py-1.5 text-[12px] text-muted" role="status" data-testid="transcript-search-count">
                                {rows.length === 0 ? 'No steps match.' : `${rows.length}${paging?.hasMore ? '+' : ''} ${rows.length === 1 && !paging?.hasMore ? 'step matches' : 'steps match'}`}
                            </div>
                        )}
                        <ol ref={listRef} data-scroller className="min-h-0 flex-1 overflow-y-auto">
                            {paging?.status === 'loading' && (
                                <li className="flex flex-col gap-2 p-3" data-testid="transcript-loading">
                                    {[0, 1, 2, 3, 4].map((line) => (
                                        <Skeleton key={line} className="h-4 w-full" />
                                    ))}
                                </li>
                            )}
                            {paging?.status === 'error' && (
                                <li className="flex flex-col items-start gap-2 p-4 text-[12px] text-fail" data-testid="transcript-error">
                                    The log could not be loaded.
                                    <Button variant="secondary" onClick={paging.onRetry} data-testid="transcript-retry">
                                        Retry
                                    </Button>
                                </li>
                            )}
                            {isReady && rows.length === 0 && (
                                <li className="p-4 text-[12px] text-faint" data-testid={isSearching ? 'transcript-no-match' : paging !== undefined ? 'transcript-empty' : undefined}>
                                    {isSearching ? <>No steps match &quot;{query.trim()}&quot;.</> : (paging?.emptyText ?? 'No entries.')}
                                </li>
                            )}
                            {rows.map((row, index) => {
                                const isSelected = row.id === selectedId;
                                return (
                                    <li key={row.id}>
                                        <button
                                            type="button"
                                            onClick={() => onSelectLog(row.id)}
                                            aria-current={isSelected ? 'true' : undefined}
                                            className={cn('flex w-full items-baseline gap-2.5 border-b border-hair px-3.5 py-[9px] text-left text-[13px] hover:bg-panel-2', isSelected && 'bg-panel-2')}
                                            data-testid={isSelected ? 'log-entry-open' : `transcript-entry-${row.id}`}
                                            {...(isSelected ? { 'data-log-selected': row.id } : {})}
                                        >
                                            <span className="tnum w-[26px] shrink-0 text-[12px] text-faint">{stepNumber(row, index)}</span>
                                            <span className="w-[56px] shrink-0 text-[12px] text-muted">{stepKind(row)}</span>
                                            <span className={cn('min-w-0 flex-1 truncate', row.kind === 'tool' && 'font-mono text-[12.5px]', row.error && 'text-fail', row.milestone && 'font-medium')}>
                                                {row.kind === 'tool' && row.subject ? row.subject : row.text}
                                            </span>
                                            <time className="tnum shrink-0 text-[11.5px] text-faint">{clock(row.at).replace(/\s?[AP]M$/, '')}</time>
                                        </button>
                                    </li>
                                );
                            })}
                            {paging?.hasMore && paging.moreFailed && (
                                <li className="flex flex-col items-start gap-2 p-3 text-[12px] text-fail" data-testid="transcript-more-error">
                                    The next steps could not be loaded.
                                    <Button variant="secondary" onClick={paging.onLoadMore} data-testid="transcript-more-retry">
                                        Retry
                                    </Button>
                                </li>
                            )}
                            {paging?.hasMore && !paging.moreFailed && (
                                <li className="p-2">
                                    <Button variant="tertiary" className="w-full" onClick={paging.onLoadMore} pending={paging.isLoadingMore} data-testid="transcript-load-more">
                                        {isSearching ? 'Load more matches' : 'Load more steps'}
                                    </Button>
                                </li>
                            )}
                        </ol>
                    </div>
                    <div className="flex min-h-0 min-w-0 flex-col" data-testid="transcript-detail">
                        <div className="min-h-0 flex-1 overflow-auto p-[18px]">
                            {current ? (
                                <>
                                    <p className="sr-only" role="status" data-testid="transcript-step-announcement">
                                        Step {sel + 1} of {rows.length}
                                        {paging?.hasMore ? '+' : ''}, {stepKind(current)}
                                    </p>
                                    {outOfWindow && <p className="mb-2 text-[11px] text-faint">This step is not in the list now</p>}
                                    <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                                        {pill && (
                                            <Badge tone={pill.tone} data-testid="step-status">
                                                {pill.label}
                                            </Badge>
                                        )}
                                        <b className="text-[14px]" data-testid="transcript-step">
                                            Step {currentNumber ?? ''}{currentNumber === null ? '' : ' · '}
                                            {stepKind(current)}
                                        </b>
                                        <span className="text-[12px] text-muted">
                                            {clock(current.at)}
                                            {current.durationSeconds ? ` · ${formatMs(current.durationSeconds * 1000)}` : ''}
                                        </span>
                                        <span className="flex-1" />
                                        {linkFor && (
                                            <Button variant="secondary" className="h-8" onClick={copyLink} data-testid="copy-step-link">
                                                {isCopied ? 'Link copied' : 'Copy link to this step'}
                                            </Button>
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
                                        <div className="mt-4 rounded-card border border-hair bg-panel p-4">
                                            <Prose html={current.html ?? ''} />
                                        </div>
                                    ) : current.kind === 'level' ? (
                                        current.details && current.details.length > 0 ? (
                                            current.details.map((detail) => (
                                                <Block key={detail.label} title={detail.label} error={detail.error}>
                                                    {detail.value}
                                                </Block>
                                            ))
                                        ) : (
                                            <p className="mt-4 text-[12px] text-faint">No details recorded for this entry.</p>
                                        )
                                    ) : (
                                        <>
                                            <Block title={current.tool === 'Bash' ? 'Command' : 'Input'}>{current.tool === 'Bash' && current.input ? `$ ${current.input}` : current.input}</Block>
                                            <Block title="Output" error={current.error}>
                                                {current.output}
                                            </Block>
                                            {current.isTruncated && <p className="mt-2 text-[11px] text-faint">Long text is cut at 16 KB.</p>}
                                        </>
                                    )}
                                </>
                            ) : (
                                <p className="text-[12px] text-faint" data-testid={paging !== undefined && rows.length === 0 && paging.status === 'ready' ? 'transcript-empty-detail' : undefined}>
                                    {paging === undefined || rows.length > 0 ? 'Loading entry…' : paging.status === 'ready' ? (isSearching ? 'No step to show.' : paging.emptyText) : paging.status === 'error' ? '' : 'Loading…'}
                                </p>
                            )}
                        </div>
                        <div className="flex shrink-0 gap-2 border-t border-hair px-[18px] py-3">
                            <Button variant="secondary" className="h-8" onClick={() => step(-1)} disabled={rows.length === 0 || (idx >= 0 && sel <= 0)} data-testid="log-prev">
                                Previous step
                            </Button>
                            <Button variant="secondary" className="h-8" onClick={() => step(1)} disabled={!hasNextStep} pending={advanceFrom !== null} data-testid="log-next">
                                Next step
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </Dialog>
    );
}
