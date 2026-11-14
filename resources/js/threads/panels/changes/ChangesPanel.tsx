import { Button, Select, Tooltip, cn } from '@geocodio/console-ui';
import { usePage } from '@inertiajs/react';
import { Check, Columns2, GitBranch, Globe, GitPullRequest, Keyboard, LoaderCircle, Rows2, TriangleAlert } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useMediaQuery } from '@/lib/useMediaQuery';
import type { PanelProps } from '@/threads/panels';
import type { ThreadDiffFile } from '@/types/thread-changes';
import type { ThreadPageData } from '@/types/threads';
import { ChangesSkeleton } from './ChangesSkeleton';
import { compareDiffs, type FileMark } from './compareDiffs';
import { ContextRepoCallout } from './ContextRepoCallout';
import { FileCard } from './FileCard';
import { FileTree } from './FileTree';
import { FilterInput } from './FilterInput';
import { OpenPullRequestButton } from './OpenPullRequestButton';
import { RepositoryChangeCard } from './RepositoryChangeCard';
import { RepositoryTabs } from './RepositoryTabs';
import { ViewedProgress } from './ViewedProgress';
import { buildFileTree, fileKey } from './buildFileTree';
import { OPEN_BY_DEFAULT, useThreadDiff } from './useThreadDiff';

/** How long the header says the diff was just updated. */
const UPDATED_NOTE_MILLISECONDS = 2500;

function storageKey(threadId: string, headSha: string): string {
    return `thread-changes-viewed:${threadId}:${headSha}`;
}

function readViewed(key: string): Set<string> {
    try {
        const stored = JSON.parse(window.localStorage.getItem(key) ?? '[]');
        return new Set(Array.isArray(stored) ? stored.filter((value): value is string => typeof value === 'string') : []);
    } catch {
        return new Set();
    }
}

function writeViewed(key: string, viewed: Set<string>): void {
    try {
        window.localStorage.setItem(key, JSON.stringify([...viewed]));
    } catch {
        // Viewed marks are a convenience; the panel works without storage.
    }
}

/** The Changed side of the Files view: every changed file as a card with its diff, a file tree beside them, and the review bar below. */
export function ChangesPanel({ thread, canDrive, stack = null, focusRequest = null, modeSwitch, onOpenFile, onSelectView }: PanelProps) {
    const { publishWarning, pullRequest } = usePage<ThreadPageData & Record<string, unknown>>().props;
    const isNarrow = !useMediaQuery('(min-width: 1024px)');
    const [childId, setChildId] = useState<string | null>(null);
    const { diff, isLoading, isRefreshing, error, reload } = useThreadDiff(thread, childId);
    const [marks, setMarks] = useState<Map<string, FileMark>>(new Map());
    const [isJustUpdated, setIsJustUpdated] = useState(false);
    const shownDiff = useRef<{ childId: string | null; diff: typeof diff }>({ childId, diff: null });
    const [filter, setFilter] = useState('');
    const [activeKey, setActiveKey] = useState<string | null>(null);
    const [selectedRepo, setSelectedRepo] = useState<string | null>(null);
    const [preferredMode, setPreferredMode] = useState<'unified' | 'split'>('split');
    const [expanded, setExpanded] = useState<Record<string, boolean>>({});
    const [viewed, setViewed] = useState<Set<string>>(new Set());
    const filterInput = useRef<HTMLInputElement>(null);
    const pane = useRef<HTMLDivElement>(null);
    const mode = isNarrow ? 'unified' : preferredMode;
    const headSha = diff?.headSha ?? '';
    const viewedStorageKey = storageKey(thread.id, headSha);

    useEffect(() => {
        setViewed(readViewed(viewedStorageKey));
    }, [viewedStorageKey]);

    // A fresh diff marks the files it added or changed since the one on screen; switching to another thread's diff starts over.
    useEffect(() => {
        if (diff === null) {
            return;
        }
        const previous = shownDiff.current.childId === childId ? shownDiff.current.diff : null;
        shownDiff.current = { childId, diff };
        if (previous === null) {
            setMarks(new Map());
            return;
        }
        const fresh = compareDiffs(previous, diff, (file) => fileKey(file, diff.repositories[0]?.repo ?? ''));
        if (fresh.size === 0) {
            return;
        }
        setMarks((current) => new Map([...current, ...fresh]));
        setIsJustUpdated(true);
    }, [diff, childId]);

    useEffect(() => {
        if (!isJustUpdated) {
            return;
        }
        const timer = setTimeout(() => setIsJustUpdated(false), UPDATED_NOTE_MILLISECONDS);
        return () => clearTimeout(timer);
    }, [isJustUpdated]);

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;
            const isTyping = target !== null && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable);
            if (event.key === '/' && !isTyping) {
                event.preventDefault();
                filterInput.current?.focus();
            }
        };
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    const repositories = diff?.repositories ?? [];
    const hasSeveralRepositories = repositories.length > 1;
    const primaryRepo = repositories[0]?.repo ?? '';
    const selectedRepository = repositories.find((repository) => repository.repo === selectedRepo) ?? null;
    const allFiles = diff?.files ?? [];
    const files = useMemo(() => (selectedRepository === null ? allFiles : allFiles.filter((file) => file.repo === selectedRepository.repo)), [allFiles, selectedRepository]);
    const visibleFiles = useMemo(() => files.filter((file) => file.path.toLowerCase().includes(filter.trim().toLowerCase())), [files, filter]);
    const tree = useMemo(() => buildFileTree(visibleFiles, primaryRepo, hasSeveralRepositories && selectedRepository === null), [visibleFiles, primaryRepo, hasSeveralRepositories, selectedRepository]);
    const keyOf = useCallback((file: ThreadDiffFile) => fileKey(file, primaryRepo), [primaryRepo]);
    const isAllReposView = hasSeveralRepositories && selectedRepo === null && childId === null;
    const pullRequestRepositories = repositories.filter((repository) => !repository.isThrowaway && repository.filesCount > 0);
    const scratchRepositories = repositories.filter((repository) => repository.isThrowaway && repository.filesCount > 0);

    const isCollapsed = (file: ThreadDiffFile, index: number) => {
        const stored = expanded[keyOf(file)];
        return stored === undefined ? index >= OPEN_BY_DEFAULT : !stored;
    };

    /** Expands a file, scrolls its card to the top of the pane and marks it. */
    const reveal = useCallback((key: string) => {
        setExpanded((current) => ({ ...current, [key]: true }));
        setActiveKey(key);
        window.requestAnimationFrame(() => {
            pane.current?.querySelector(`[data-file-key="${CSS.escape(key)}"]`)?.scrollIntoView({ block: 'start', behavior: 'smooth' });
        });
    }, []);

    useEffect(() => {
        if (focusRequest === null || diff === null) {
            return;
        }
        const focusPath = focusRequest.path;
        const match = diff.files.find((file) => file.path === focusPath || focusPath.endsWith(`/${file.path}`));
        if (match !== undefined) {
            reveal(fileKey(match, diff.repositories[0]?.repo ?? ''));
        }
    }, [focusRequest, diff, reveal]);

    const toggleViewed = (key: string) => {
        const next = new Set(viewed);
        if (!next.delete(key)) {
            next.add(key);
            setMarks((current) => {
                const remaining = new Map(current);
                remaining.delete(key);
                return remaining;
            });
        }
        setViewed(next);
        writeViewed(viewedStorageKey, next);
    };

    const switchOptions = stack?.isRoot && stack.children.length > 0 ? [{ value: '', label: 'This thread' }, ...stack.children.map((child) => ({ value: String(child.id), label: child.filesChanged === null ? child.name : `${child.name} (${child.filesChanged} ${child.filesChanged === 1 ? 'file' : 'files'})` }))] : [];
    const diffSwitch = switchOptions.length > 0 && (
        <div className="flex items-center gap-2">
            <span className="shrink-0 text-[12px] text-muted">Showing</span>
            <Select
                options={switchOptions}
                value={childId === null ? '' : String(childId)}
                onChange={(value) => { setChildId(value || null); setSelectedRepo(null); setActiveKey(null); }}
                aria-label="Show changes of"
                data-testid="changes-child-switch"
                className="min-w-0 flex-1"
            />
        </div>
    );

    if (diff === null && isLoading) {
        return <ChangesSkeleton isNarrow={isNarrow} actions={diffSwitch || undefined} />;
    }

    if (diff === null || (diff.filesCount === 0 && diff.throwawayFilesCount === 0)) {
        const isWorking = thread.runnerState === 'busy' && childId === null;

        return (
            <div className="flex min-h-0 flex-1 flex-col gap-3 p-4" data-testid="changes-panel">
                {modeSwitch !== undefined && <div className="w-[250px]">{modeSwitch}</div>}
                {diffSwitch}
                <div className="flex flex-1 items-center justify-center p-6">
                    {diff === null && error !== null ? (
                        <div className="flex max-w-[380px] flex-col items-center gap-1.5 text-center" data-testid="changes-error">
                            <span className="mb-1 inline-flex size-8 items-center justify-center rounded-full bg-fail-soft text-fail">
                                <TriangleAlert size={15} aria-hidden />
                            </span>
                            <p className="text-[14px] font-medium text-body">Could not load the changes</p>
                            <p className="text-[12.5px] text-muted">The server did not answer ({error}). Your files are safe in the sandbox.</p>
                            <Button variant="secondary" className="mt-2 h-7" onClick={reload} data-testid="changes-retry">
                                Retry
                            </Button>
                        </div>
                    ) : (
                        <div className="flex w-full max-w-[380px] flex-col items-center gap-1.5 text-center" data-testid="changes-empty">
                            {isWorking && (
                                <span className="mb-2 inline-flex items-center gap-2 rounded-full bg-accent-soft px-3 py-1 text-[12px] font-medium text-accent-text" data-testid="changes-working">
                                    <span className="activity-pulse size-2 rounded-full bg-accent" aria-hidden />
                                    Claude is working
                                </span>
                            )}
                            <p className="text-[14px] font-medium text-body">No changes yet</p>
                            <p className="text-[12.5px] text-muted">{isWorking ? 'Files Claude edits in the sandbox show up here when this turn ends.' : 'Files Claude edits in the sandbox show up here after each turn.'}</p>
                            {isWorking && (
                                <div className="mt-3 flex w-full flex-col gap-1.5" aria-hidden>
                                    {[1, 0.6, 0.3].map((opacity) => (
                                        <div key={opacity} className="h-[34px] rounded-[10px] border border-dashed border-hair" style={{ opacity }} />
                                    ))}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>
        );
    }

    const viewedCount = files.filter((file) => viewed.has(keyOf(file))).length;
    const canOpenPullRequest = canDrive && childId === null && thread.status === 'open';

    const summary = (
        <p className="text-[13px] text-body" data-testid="changes-summary">
            {isAllReposView ? (
                <span className="font-medium">{diff.filesCount} {diff.filesCount === 1 ? 'file' : 'files'} in {pullRequestRepositories.length} {pullRequestRepositories.length === 1 ? 'repo' : 'repos'}</span>
            ) : (
                <span className="font-medium">{diff.filesCount} {diff.filesCount === 1 ? 'file' : 'files'} changed</span>
            )}{' '}
            <span className="font-mono text-[12px] font-medium tabular-nums">
                <span className="text-ok">+{diff.additions}</span> <span className="text-fail">-{diff.deletions}</span>
            </span>
            {selectedRepo === null && scratchRepositories.length > 0 && (
                <span className="text-muted" data-testid="changes-scratch-note">
                    {isAllReposView ? ', ' : ' '}
                    plus {diff.throwawayFilesCount} scratch {diff.throwawayFilesCount === 1 ? 'file' : 'files'} in {scratchRepositories.map((repository) => (isAllReposView ? repository.repo : repository.repo.split('/').pop())).join(', ')}
                </span>
            )}
            {isAllReposView && (
                <span className="text-muted" data-testid="changes-pull-request-sentence">
                    . {pullRequestRepositories.length} {pullRequestRepositories.length === 1 ? 'pull request' : 'pull requests'} will open, one per repo with changes.
                </span>
            )}
        </p>
    );

    return (
        <div className="flex min-h-0 flex-1 flex-col" data-testid="changes-panel">
            <div className="flex min-h-[45px] shrink-0 flex-wrap items-center gap-x-3 gap-y-1 border-b border-hair px-3 py-2">
                {summary}
                {!isNarrow && thread.branch !== null && (
                    <span className="inline-flex min-w-0 items-center gap-1.5 text-[12.5px] text-muted">
                        <GitBranch size={12} className="shrink-0" aria-hidden />
                        <code className="truncate font-mono text-[12px]">{thread.branch}</code>
                    </span>
                )}
                {error !== null ? (
                    <span className="inline-flex items-center gap-1.5 text-[12px] text-muted" data-testid="changes-sync-note">
                        Could not refresh
                        <button type="button" onClick={reload} className="cursor-pointer text-accent-text hover:underline">
                            Retry
                        </button>
                    </span>
                ) : isRefreshing ? (
                    <span className="inline-flex items-center gap-1.5 text-[12px] text-muted" data-testid="changes-sync-note">
                        <LoaderCircle size={11} className="activity-spin" aria-hidden />
                        Checking
                    </span>
                ) : (
                    isJustUpdated && (
                        <span className="activity-rise text-[12px] text-muted" data-testid="changes-sync-note">
                            Updated just now
                        </span>
                    )
                )}
                <span className="grow" />
                {diffSwitch}
                {!isNarrow && (
                    <>
                        <Tooltip label="/ filter files">
                            <button type="button" aria-label="Keyboard shortcuts" className="cursor-pointer text-muted hover:text-body">
                                <Keyboard size={14} />
                            </button>
                        </Tooltip>
                        <div role="group" aria-label="Diff layout" className="inline-flex gap-0.5 rounded-[9px] bg-panel-2 p-0.5">
                            {([['split', 'Split', Columns2], ['unified', 'Unified', Rows2]] as const).map(([option, label, Icon]) => (
                                <button
                                    key={option}
                                    type="button"
                                    onClick={() => setPreferredMode(option)}
                                    aria-pressed={mode === option}
                                    data-testid={`changes-mode-${option}`}
                                    className={cn('inline-flex h-7 cursor-pointer items-center gap-1.5 rounded-[7px] px-2.5 text-[13px]', mode === option ? 'bg-panel font-medium text-body shadow-[0_0_0_1px_var(--hair),0_1px_2px_rgb(0_0_0/0.1)]' : 'text-muted hover:text-body')}
                                >
                                    <Icon size={13} aria-hidden />
                                    {label}
                                </button>
                            ))}
                        </div>
                    </>
                )}
            </div>

            {(diff.isTurnRunning || diff.isTruncated || (hasSeveralRepositories && childId === null) || (selectedRepository?.isThrowaway === true && childId === null)) && (
                <div className="flex shrink-0 flex-col gap-2 border-b border-hair px-3 py-2">
                    {hasSeveralRepositories && childId === null && <RepositoryTabs repositories={repositories} totalFilesCount={diff.filesCount} selected={selectedRepo} onSelect={(repo) => { setSelectedRepo(repo); setActiveKey(null); }} />}
                    {selectedRepository?.isThrowaway === true && childId === null && <ContextRepoCallout thread={thread} repo={selectedRepository.repo} canDrive={canDrive} onPromoted={reload} />}
                    {diff.isTurnRunning && (
                        <p className="rounded-card bg-panel-2 px-3 py-2 text-[12px] text-muted" data-testid="changes-working-note">
                            Claude is still working, showing the diff from the last finished turn.
                        </p>
                    )}
                    {diff.isTruncated && <p className="text-[12px] text-muted">This diff is large, so some files are listed without their changes.</p>}
                </div>
            )}

            <div className="flex min-h-0 flex-1">
                {!isNarrow && (
                    <nav aria-label="Changed files" className={cn('flex shrink-0 flex-col gap-3 overflow-auto border-r border-hair bg-app p-3', isAllReposView ? 'w-[280px]' : 'w-[250px]')} data-testid="changes-file-nav">
                        <div className="flex flex-col gap-2">
                            {modeSwitch}
                            <FilterInput ref={filterInput} value={filter} onChange={setFilter} />
                            <ViewedProgress viewed={viewedCount} total={files.length} />
                        </div>
                        {isAllReposView ? (
                            <div className="flex flex-col gap-3">
                                {repositories.map((repository) => (
                                    <RepositoryChangeCard
                                        key={repository.repo}
                                        repository={repository}
                                        files={visibleFiles.filter((file) => file.repo === repository.repo)}
                                        primaryRepo={primaryRepo}
                                        viewed={viewed}
                                        onOpen={reveal}
                                        onReview={setSelectedRepo}
                                    />
                                ))}
                            </div>
                        ) : (
                            <FileTree nodes={tree} viewed={viewed} onOpen={reveal} marks={marks} />
                        )}
                    </nav>
                )}

                <div ref={pane} className="min-w-0 flex-1 overflow-auto bg-app" data-testid="changes-pane">
                    <div className="flex flex-col gap-3.5 p-3.5 max-lg:p-2.5">
                        {visibleFiles.length === 0 && <p className="text-[13px] text-muted">No files match the filter.</p>}
                        {visibleFiles.map((file, index) => {
                            const key = keyOf(file);

                            return (
                                <FileCard
                                    key={key}
                                    file={file}
                                    fileKey={key}
                                    label={`${hasSeveralRepositories ? `${file.repo}: ` : ''}${file.path}`}
                                    threadId={childId ?? thread.id}
                                    mode={mode}
                                    isCollapsed={isCollapsed(file, index)}
                                    isActive={activeKey === key}
                                    isViewed={viewed.has(key)}
                                    mark={marks.get(key)}
                                    canExpand={childId === null && file.repo === thread.repo}
                                    isNarrow={isNarrow}
                                    onToggleCollapsed={() => setExpanded((current) => ({ ...current, [key]: isCollapsed(file, index) }))}
                                    onToggleViewed={() => toggleViewed(key)}
                                    onEditFile={childId === null && file.repo === thread.repo && file.status !== 'deleted' && onOpenFile !== undefined ? () => onOpenFile(file.path) : undefined}
                                />
                            );
                        })}
                    </div>
                </div>
            </div>

            <div className="flex shrink-0 items-center gap-2.5 border-t border-hair bg-app px-3.5 py-2.5 text-[12.5px]" data-testid="changes-review-bar">
                {pullRequest !== null ? (
                    <>
                        <Check size={14} className="shrink-0 text-ok" aria-hidden />
                        <span className="min-w-0 flex-1 truncate">PR #{pullRequest.number} is open for review.</span>
                        <a href={pullRequest.url} target="_blank" rel="noreferrer" className="inline-flex h-7 shrink-0 items-center gap-1.5 rounded-[8px] border border-hair bg-panel px-2.5 text-[12.5px] font-medium hover:bg-panel-2" data-testid="changes-review-pr">
                            <GitPullRequest size={12} aria-hidden />#{pullRequest.number}
                        </a>
                    </>
                ) : (
                    <>
                        <span className="min-w-0 flex-1 text-muted">{isNarrow ? 'Ready for review?' : 'Reviewed the diff? Open a PR for your team to review and merge.'}</span>
                        {isNarrow && canOpenPullRequest ? (
                            <OpenPullRequestButton thread={thread} publishWarning={publishWarning} />
                        ) : (
                            !isNarrow && onSelectView !== undefined && (
                                <Button variant="secondary" className="h-7 shrink-0" icon={<Globe size={12} />} onClick={() => onSelectView('preview')} data-testid="changes-check-preview">
                                    Check preview
                                </Button>
                            )
                        )}
                    </>
                )}
            </div>
        </div>
    );
}
