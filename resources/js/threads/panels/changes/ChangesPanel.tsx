import { Button, Select, TextInput, cn } from '@geocodio/console-ui';
import { usePage } from '@inertiajs/react';
import { ArrowLeft, ChevronLeft, ChevronRight, Maximize2, Minimize2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { PanelProps } from '@/threads/panels';
import type { ThreadDiffFile } from '@/types/thread-changes';
import { DiffViewer } from './DiffViewer';
import { OpenPullRequestButton } from './OpenPullRequestButton';
import { ContextRepoCallout } from './ContextRepoCallout';
import { FileTree } from './FileTree';
import { RepositoryTabs } from './RepositoryTabs';
import { buildFileTree, fileKey } from './buildFileTree';
import { useStack } from '../stack/useStack';
import { useThreadDiff } from './useThreadDiff';

function storageKey(threadId: number, headSha: string): string {
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

export function ChangesPanel({ thread, canDrive }: PanelProps) {
    const { publishWarning } = usePage<{ publishWarning: boolean } & Record<string, unknown>>().props;
    const { stack } = useStack(thread);
    const [childId, setChildId] = useState<number | null>(null);
    const { diff, isLoading, error, reload } = useThreadDiff(thread, childId);
    const switchOptions = stack?.isRoot && stack.children.length > 0 ? [{ value: '', label: 'This thread' }, ...stack.children.map((child) => ({ value: String(child.id), label: child.name }))] : [];
    const diffSwitch = switchOptions.length > 0 && (
        <Select
            options={switchOptions}
            value={childId === null ? '' : String(childId)}
            onChange={(value) => { setChildId(value ? Number(value) : null); setOpenPath(null); }}
            aria-label="Show changes of"
            data-testid="changes-child-switch"
        />
    );
    const diffThreadId = childId ?? thread.id;
    const [filter, setFilter] = useState('');
    const [openPath, setOpenPath] = useState<string | null>(null);
    const [selectedRepo, setSelectedRepo] = useState<string | null>(null);
    const [mode, setMode] = useState<'unified' | 'split'>('unified');
    const [isExpanded, setIsExpanded] = useState(false);
    const [viewed, setViewed] = useState<Set<string>>(new Set());
    const filterInput = useRef<HTMLInputElement>(null);
    const headSha = diff?.headSha ?? '';
    const key = storageKey(thread.id, headSha);

    useEffect(() => {
        setViewed(readViewed(key));
    }, [key]);

    useEffect(() => {
        if (isExpanded) {
            document.body.dataset.threadChangesExpanded = 'true';
        } else {
            delete document.body.dataset.threadChangesExpanded;
        }
        return () => {
            delete document.body.dataset.threadChangesExpanded;
        };
    }, [isExpanded]);

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
    const keyOf = (file: ThreadDiffFile) => fileKey(file, primaryRepo);
    const selectRepository = (repo: string | null) => {
        setSelectedRepo(repo);
        setOpenPath(null);
    };
    const scratchRepositories = repositories.filter((repository) => repository.isThrowaway && repository.filesCount > 0);

    const toggleViewed = (path: string) => {
        const next = new Set(viewed);
        if (!next.delete(path)) {
            next.add(path);
        }
        setViewed(next);
        writeViewed(key, next);
    };

    if (diff === null || (diff.filesCount === 0 && diff.throwawayFilesCount === 0)) {
        return (
            <div className="flex flex-col gap-3" data-testid="changes-panel">
                {diffSwitch}
                {diff === null && isLoading ? (
                    <p className="text-[13px] text-muted">Loading changes</p>
                ) : error !== null && diff === null ? (
                    <p className="text-[13px] text-muted" data-testid="changes-error">
                        {error}
                    </p>
                ) : (
                    <div data-testid="changes-empty">
                        <p className="text-[13px] font-medium text-body">No changes yet</p>
                        <p className="mt-1 text-[12px] text-muted">Files Claude edits in the sandbox show up here after each turn.</p>
                    </div>
                )}
            </div>
        );
    }

    const openIndex = openPath === null ? -1 : files.findIndex((file) => keyOf(file) === openPath);
    const openFile = openIndex >= 0 ? files[openIndex] : null;
    const viewedCount = files.filter((file) => viewed.has(keyOf(file))).length;
    const step = (delta: number) => setOpenPath(keyOf(files[(openIndex + delta + files.length) % files.length]));

    return (
        <div className="flex flex-col gap-3" data-testid="changes-panel">
            {diffSwitch}
            <div className="flex items-center justify-between gap-2">
                <p className="text-[13px] text-body" data-testid="changes-summary">
                    <span className="font-medium">{diff.filesCount} {diff.filesCount === 1 ? 'file' : 'files'} changed</span>{' '}
                    <span className="text-ok">+{diff.additions}</span> <span className="text-fail">-{diff.deletions}</span>
                    {selectedRepo === null && scratchRepositories.length > 0 && (
                        <span className="text-muted" data-testid="changes-scratch-note">
                            {' '}
                            plus {diff.throwawayFilesCount} scratch {diff.throwawayFilesCount === 1 ? 'file' : 'files'} in {scratchRepositories.map((repository) => repository.repo.split('/').pop()).join(', ')}
                        </span>
                    )}
                </p>
                <div className="flex items-center gap-2">
                    {canDrive && childId === null && <OpenPullRequestButton thread={thread} publishWarning={publishWarning} />}
                    <button type="button" onClick={() => setIsExpanded(!isExpanded)} aria-label={isExpanded ? 'Shrink to sidebar width' : 'Expand to full width'} className="cursor-pointer text-muted hover:text-body">
                        {isExpanded ? <Minimize2 size={14} /> : <Maximize2 size={14} />}
                    </button>
                </div>
            </div>

            {hasSeveralRepositories && childId === null && <RepositoryTabs repositories={repositories} totalFilesCount={diff.filesCount} selected={selectedRepo} onSelect={selectRepository} />}

            {selectedRepository?.isThrowaway === true && childId === null && <ContextRepoCallout thread={thread} repo={selectedRepository.repo} canDrive={canDrive} onPromoted={reload} />}

            {diff.isTurnRunning && (
                <p className="sticky top-0 rounded-card bg-app px-3 py-2 text-[12px] text-muted" data-testid="changes-working-note">
                    Claude is still working, showing the diff from the last finished turn.
                </p>
            )}

            {diff.isTruncated && <p className="text-[12px] text-muted">This diff is large, so some files are listed without their changes.</p>}

            {openFile === null ? (
                <>
                    <TextInput ref={filterInput} value={filter} onChange={(event) => setFilter(event.target.value)} placeholder="Filter files (press /)" aria-label="Filter files" />
                    <p className="text-[12px] text-muted">{viewedCount} of {files.length} viewed</p>
                    <FileTree nodes={tree} viewed={viewed} onToggleViewed={toggleViewed} onOpen={setOpenPath} />
                </>
            ) : (
                <>
                    <div className="flex items-center justify-between gap-2">
                        <Button variant="tertiary" onClick={() => setOpenPath(null)}>
                            <ArrowLeft size={13} /> All files
                        </Button>
                        <div className="flex items-center gap-1 text-[12px] text-muted">
                            <button type="button" onClick={() => step(-1)} aria-label="Previous file" className="cursor-pointer hover:text-body">
                                <ChevronLeft size={14} />
                            </button>
                            <span className="tabular-nums">{openIndex + 1}/{files.length}</span>
                            <button type="button" onClick={() => step(1)} aria-label="Next file" className="cursor-pointer hover:text-body">
                                <ChevronRight size={14} />
                            </button>
                        </div>
                    </div>
                    <div className="flex items-center justify-between gap-2">
                        <code className="truncate font-mono text-[12px] text-body">{hasSeveralRepositories ? `${openFile.repo}: ` : ''}{openFile.path}</code>
                        <div className="flex shrink-0 gap-1 text-[12px]" role="group" aria-label="Diff layout">
                            {(['unified', 'split'] as const).map((option) => (
                                <button key={option} type="button" onClick={() => setMode(option)} aria-pressed={mode === option} className={cn('cursor-pointer rounded-chip px-2 py-0.5', mode === option ? 'bg-app text-body' : 'text-muted')}>
                                    {option === 'unified' ? 'Unified' : 'Split'}
                                </button>
                            ))}
                        </div>
                    </div>
                    <label className="flex items-center gap-2 text-[12px] text-muted">
                        <input type="checkbox" checked={viewed.has(keyOf(openFile))} onChange={() => toggleViewed(keyOf(openFile))} />
                        Viewed
                    </label>
                    <DiffViewer key={openFile.id} threadId={diffThreadId} file={openFile} mode={mode} />
                </>
            )}
        </div>
    );
}
