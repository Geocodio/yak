import { Link, router, usePoll } from '@inertiajs/react';
import { Button, StackedTable, StackedTbody, StackedTd, StackedThead, StackedTr, StatusPill, Th, Tr, cn } from '@geocodio/console-ui';
import { ArrowLeft, RefreshCw, Search } from 'lucide-react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Prose } from '@/components/Prose';
import { RunLogOverlay, type RunLogTarget } from '@/components/threads/conversation/RunLogOverlay';
import { RepositorySettingsLayout } from '@/layouts/RepositorySettingsLayout';
import { AppLayout } from '@/layouts/AppLayout';
import { rowLinkHandlers } from '@/lib/visitRow';
import { formatMs, formatUsd, formatWhen } from '@/lib/format';
import { useRetained } from '@/lib/useRetained';
import { useRouterAction } from '@/lib/useRouterAction';
import type { YakConfig } from '@/types/repositories';
import type { RepositoryRunDetail, RepositoryRunKind, RepositoryRunRow, RepositoryRunStatus } from '@/types/repositoryRuns';
import type { RepositorySettingsHeader } from '@/types/repositorySettings';
import type { PageProps } from '@/types/shared';

type Props = PageProps<{
    header: RepositorySettingsHeader;
    runs: RepositoryRunRow[];
    totalCount: number;
    selected: RepositoryRunDetail | null;
    selectedUrl: string | null;
    filters: { kind: string | null; status: string | null };
    isRunOpen: boolean;
    modelLabels: Record<string, string>;
    rerunSetupUrl: string;
    researchUrl: string;
    settingsUrl: string;
    listUrl: string;
    canResearch: boolean;
    yakConfig?: YakConfig;
}>;

const STATUS: Record<RepositoryRunStatus, { label: string; tone: 'ok' | 'fail' | 'info' | 'idle'; live?: boolean }> = {
    queued: { label: 'Queued', tone: 'idle', live: true },
    running: { label: 'Running', tone: 'info', live: true },
    ready: { label: 'Ready', tone: 'ok' },
    done: { label: 'Done', tone: 'ok' },
    failed: { label: 'Failed', tone: 'fail' },
    cancelled: { label: 'Cancelled', tone: 'idle' },
};

const RECENT_RUN_POLL_MINUTES = 30;
const KIND_LABEL: Record<RepositoryRunKind, string> = { setup: 'Setup', repository_research: 'Repository research' };
const DOT: Record<string, string> = { ok: 'bg-ok', fail: 'bg-fail', info: 'bg-info', idle: 'bg-faint' };
const selectClass = 'h-8 rounded-control border border-hair bg-panel px-2 text-[13px] text-body';
const linkButtonClass = 'inline-flex h-8 items-center gap-1.5 rounded-[8px] border border-hair bg-panel px-3 text-[13px] font-medium text-body hover:bg-panel-2';

function RunList({ onPick, runs, selectedId, filters, totalCount }: { onPick: () => void; runs: RepositoryRunRow[]; selectedId: string | null; filters: Props['filters']; totalCount: number }) {
    const setFilter = (key: 'kind' | 'status', value: string) => {
        router.get(window.location.pathname, { ...filters, [key]: value === '' ? undefined : value }, { preserveScroll: true, preserveState: true, replace: true });
    };

    return (
        <div className="min-w-0" data-testid="run-list">
            <div className="flex flex-wrap items-center gap-2 pb-3">
                <select aria-label="Kind" value={filters.kind ?? ''} onChange={(event) => setFilter('kind', event.target.value)} className={selectClass} data-testid="run-filter-kind">
                    <option value="">All kinds</option>
                    <option value="setup">Setup</option>
                    <option value="repository_research">Repository research</option>
                </select>
                <select aria-label="Status" value={filters.status ?? ''} onChange={(event) => setFilter('status', event.target.value)} className={selectClass} data-testid="run-filter-status">
                    <option value="">All statuses</option>
                    <option value="queued">Queued</option>
                    <option value="running">Running</option>
                    <option value="finished">Finished</option>
                    <option value="failed">Failed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <span className="flex-1" />
                <span className="text-[12px] text-muted">{totalCount} {totalCount === 1 ? 'run' : 'runs'}</span>
            </div>
            {runs.length === 0 ? (
                <p className="rounded-card border border-hair bg-panel px-4 py-8 text-center text-[13px] text-muted" data-testid="run-list-empty">No runs match these filters.</p>
            ) : (
                <div scroll-region="" className="max-h-[70vh] overflow-y-auto rounded-card border border-hair bg-panel shadow-card">
                    <StackedTable className="w-full">
                        <StackedThead>
                            <Tr>
                                <Th className="w-6" />
                                <Th>Run</Th>
                                <Th>Started by</Th>
                                <Th className="text-right">Cost</Th>
                                <Th className="text-right">When</Th>
                            </Tr>
                        </StackedThead>
                        <StackedTbody>
                            {runs.map((run) => {
                                const status = STATUS[run.status];

                                return (
                                    <StackedTr
                                        key={run.id}
                                        className={cn('cursor-pointer hover:bg-panel-2', run.id === selectedId && 'bg-panel-2')}
                                        aria-current={run.id === selectedId ? 'true' : undefined}
                                        data-testid={`run-row-${run.id}`}
                                        data-status={run.status}
                                        data-selected={run.id === selectedId}
                                        onClickCapture={onPick}
                                        {...rowLinkHandlers(run.url)}
                                    >
                                        <StackedTd label="Status" className="w-6">
                                            <span className={cn('inline-block size-2 rounded-full', DOT[status.tone])} role="img" aria-label={status.label} />
                                        </StackedTd>
                                        <StackedTd label="Run">
                                            <Link href={run.url} preserveScroll preserveState className="block min-w-0">
                                                <span className="block text-[13px] font-semibold text-body">{KIND_LABEL[run.kind]}</span>
                                                <span className="block truncate text-[12px] text-muted">{run.resultLine}</span>
                                            </Link>
                                        </StackedTd>
                                        <StackedTd label="Started by" className="text-[12px] text-muted">{run.startedBy}</StackedTd>
                                        <StackedTd label="Cost" className="tnum text-[12px] text-muted md:text-right">{run.costUsd > 0 ? formatUsd(run.costUsd) : '–'}</StackedTd>
                                        <StackedTd label="When" className="text-[12px] text-muted md:text-right">{formatWhen(run.startedAt)}</StackedTd>
                                    </StackedTr>
                                );
                            })}
                        </StackedTbody>
                    </StackedTable>
                </div>
            )}
        </div>
    );
}

function FailedCard({ run, settingsUrl }: { run: RepositoryRunDetail; settingsUrl: string }) {
    const action = useRouterAction();
    const [showDetails, setShowDetails] = useState(false);
    const title = run.kind === 'setup' ? 'Setup could not finish' : 'The research could not finish';

    return (
        <div className="rounded-card border border-hair bg-fail-soft p-4" data-testid="run-failed-card">
            <p className="text-[13px] font-semibold text-body">{title}</p>
            <p className="mt-1 whitespace-pre-wrap break-words text-[13px] text-muted" data-testid="run-error">{showDetails && run.errorDetails !== null ? run.errorDetails : run.error}</p>
            {run.errorIsTruncated && (
                <button type="button" onClick={() => setShowDetails((value) => !value)} className="mt-1 text-[12px] text-accent-text hover:underline" data-testid="run-error-toggle">
                    {showDetails ? 'Hide details' : 'Show details'}
                </button>
            )}
            <div className="mt-3 flex flex-wrap gap-2">
                {run.canRetry && run.retryUrl !== null && (
                    <Button
                        variant="secondary"
                        icon={<RefreshCw size={13} />}
                        pending={action.isPending('retry')}
                        pendingLabel="Retrying…"
                        onClick={() => action.run('retry', 'post', run.retryUrl as string)}
                        data-testid="run-retry"
                    >
                        Retry setup
                    </Button>
                )}
                {run.kind === 'setup' && (
                    <Link href={settingsUrl} className={linkButtonClass} data-testid="run-open-settings">
                        Open the sandbox settings
                    </Link>
                )}
            </div>
        </div>
    );
}

function RememberedCard({ run }: { run: RepositoryRunDetail }) {
    const remembered = run.remembered;

    if (remembered === null) {
        return null;
    }

    const rows: [string, string | null, boolean][] = [
        ['Test command', remembered.testCommand, true],
        ['Preview port', remembered.previewPort === null ? null : String(remembered.previewPort), true],
        ['Start command', remembered.startCommand, true],
        ['Snapshot', remembered.snapshot, false],
    ];

    return (
        <section data-testid="run-remembered">
            <h4 className="mb-2 text-[13px] font-semibold text-body">What Yak remembers for this repository</h4>
            <dl className="space-y-1.5 rounded-card border border-hair bg-panel p-4 shadow-card">
                {rows
                    .filter(([, value]) => value !== null)
                    .map(([label, value, isMono]) => (
                        <div key={label} className="flex items-baseline justify-between gap-3 text-[13px]">
                            <dt className="text-muted">{label}</dt>
                            <dd className={cn('min-w-0 break-words text-right text-body', isMono && 'font-mono text-[12px]')}>{value}</dd>
                        </div>
                    ))}
            </dl>
        </section>
    );
}

function RunDetail({ run, modelLabels, settingsUrl, onOpenLog }: { run: RepositoryRunDetail; modelLabels: Record<string, string>; settingsUrl: string; onOpenLog: () => void }) {
    const status = STATUS[run.status];
    const meta = [
        formatWhen(run.startedAt),
        run.model === null ? null : (modelLabels[run.model] ?? run.model),
        run.stepCount > 0 ? `${run.stepCount} steps` : null,
        run.durationSeconds > 0 ? formatMs(run.durationSeconds * 1000) : null,
        run.costUsd > 0 ? formatUsd(run.costUsd) : null,
    ].filter((part) => part !== null);

    return (
        <div className="space-y-4" data-testid="run-detail" data-run-id={run.id} data-status={run.status}>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                <StatusPill tone={status.tone} label={status.label} pulse={status.live} />
                <b className="text-[16px] text-body">{run.kind === 'setup' ? 'Setup run' : 'Repository research'}</b>
                <span className="min-w-0 text-[12px] text-muted">{meta.join(' · ')}</span>
                <span className="flex-1" />
                <Button variant="secondary" onClick={onOpenLog} data-testid="run-full-log">
                    Full log
                </Button>
            </div>

            {run.isLive && (
                <p className="text-[13px] text-muted" data-testid="run-live">
                    {run.status === 'queued' ? 'Waiting for a free sandbox.' : 'Yak is working on this run.'}
                </p>
            )}

            {run.durationSeconds > 0 && (
                <div className="flex flex-wrap items-center gap-x-2 text-[13px] text-muted" data-testid="run-work-row">
                    <b className="text-body">Worked for {formatMs(run.durationSeconds * 1000)}</b>
                    {run.stepCount > 0 && <span>· {run.stepCount} steps</span>}
                    <span className="flex-1" />
                    <button type="button" onClick={onOpenLog} className="text-accent-text hover:underline">
                        Show the steps
                    </button>
                </div>
            )}

            {run.kind === 'repository_research' && run.status !== 'failed' && <p className="text-[13px] text-body" data-testid="run-result-line">{run.resultLine}</p>}
            {run.answerHtml !== null && <Prose html={run.answerHtml} className="text-[13px]" />}

            {run.kind === 'repository_research' && (
                <div className="flex flex-wrap gap-2">
                    {run.riskProfileUrl !== null && (
                        <Link href={run.riskProfileUrl} className={linkButtonClass} data-testid="run-risk-profile">
                            Open the risk profile
                        </Link>
                    )}
                    {run.threadUrl !== null && (
                        <Link href={run.threadUrl} className={linkButtonClass} data-testid="run-open-thread">
                            Open as a thread
                        </Link>
                    )}
                </div>
            )}

            <RememberedCard run={run} />

            {run.waitingThreads.length > 0 && (
                <section className="rounded-card border border-hair bg-panel p-4 shadow-card" data-testid="run-waiting-threads">
                    <h4 className="mb-2 text-[13px] font-semibold text-body">Threads that wait for this setup</h4>
                    <ul className="space-y-1 text-[13px]">
                        {run.waitingThreads.map((thread) => (
                            <li key={thread.id}>
                                <Link href={thread.url} className="text-accent-text hover:underline">
                                    {thread.title}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {run.error !== null && <FailedCard run={run} settingsUrl={settingsUrl} />}
        </div>
    );
}

export default function Runs({ header, runs, totalCount, selected, selectedUrl, filters, isRunOpen, modelLabels, rerunSetupUrl, researchUrl, settingsUrl, listUrl, canResearch, yakConfig: loadedYakConfig }: Props) {
    const yakConfig = useRetained(`yak-config:${header.slug}`, loadedYakConfig);
    const action = useRouterAction();
    const [isListView, setIsListView] = useState(!isRunOpen);
    const [logTarget, setLogTarget] = useState<RunLogTarget | null>(null);
    const isLive = useMemo(() => {
        const recentSince = Date.now() - RECENT_RUN_POLL_MINUTES * 60_000;

        return selected?.isLive === true || runs.some((run) => run.isLive && new Date(run.startedAt).getTime() >= recentSince);
    }, [runs, selected?.isLive]);
    const poll = usePoll(5000, { only: ['runs', 'selected', 'selectedUrl', 'totalCount'] }, { autoStart: false });

    useEffect(() => {
        if (isLive) {
            poll.start();
        } else {
            poll.stop();
        }

        return () => poll.stop();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isLive]);

    useEffect(() => {
        if (!isRunOpen && selectedUrl !== null) {
            router.replace({ url: selectedUrl, preserveScroll: true, preserveState: true });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const hasAnyRun = totalCount > 0 || filters.kind !== null || filters.status !== null;
    const showDetailOnPhone = !isListView && selected !== null;

    const buttons = (
        <div className="flex items-center gap-2">
            <Button variant="secondary" disabled={!canResearch} pending={action.isPending('research')} pendingLabel="Starting…" onClick={() => action.run('research', 'post', researchUrl, { action: 'generate' })} data-testid="research-again">
                Research again
            </Button>
            <Button variant="primary" icon={<RefreshCw size={13} />} pending={action.isPending('rerun-setup')} pendingLabel="Dispatching…" onClick={() => action.run('rerun-setup', 'post', rerunSetupUrl)} data-testid="run-setup-again">
                {hasAnyRun ? 'Run setup again' : 'Run setup'}
            </Button>
        </div>
    );

    return (
        <RepositorySettingsLayout header={header} yakConfig={yakConfig} active="runs" actions={buttons}>
            {!hasAnyRun ? (
                <div className="rounded-card border border-hair bg-panel px-5 py-12 text-center text-[13px] text-muted shadow-card" data-testid="runs-empty">
                    <Search className="mx-auto mb-2 text-faint" size={18} aria-hidden />
                    <p>No runs yet. Run setup to prepare this repository.</p>
                </div>
            ) : (
                <div className="grid gap-6 lg:grid-cols-[minmax(260px,340px)_minmax(0,1fr)]">
                    <div className={cn(showDetailOnPhone && 'hidden lg:block')}>
                        <RunList onPick={() => setIsListView(false)} runs={runs} selectedId={selected?.id ?? null} filters={filters} totalCount={totalCount} />
                    </div>
                    <div className={cn('min-w-0', !showDetailOnPhone && 'hidden lg:block')}>
                        <Link href={listUrl} onClick={() => setIsListView(true)} className="mb-3 inline-flex items-center gap-1 text-[13px] text-accent-text hover:underline lg:hidden" data-testid="runs-back">
                            <ArrowLeft size={13} aria-hidden /> All runs
                        </Link>
                        {selected !== null ? (
                            <RunDetail
                                run={selected}
                                modelLabels={modelLabels}
                                settingsUrl={settingsUrl}
                                onOpenLog={() => setLogTarget({ kind: 'url', id: selected.logUrl, entryId: null, headline: `${selected.kind === 'setup' ? 'Setup' : 'Repository research'} at ${formatWhen(selected.startedAt)}` })}
                            />
                        ) : (
                            <p className="text-[13px] text-muted">Pick a run to see it in full.</p>
                        )}
                    </div>
                </div>
            )}
            <RunLogOverlay target={logTarget} onClose={() => setLogTarget(null)} />
        </RepositorySettingsLayout>
    );
}

Runs.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
