import { Button, cn } from '@geocodio/console-ui';
import { usePage } from '@inertiajs/react';
import { ChevronRight, FileCode2, FileDiff, X } from 'lucide-react';
import { lazy, Suspense, useEffect, useMemo, useState } from 'react';
import { csrfToken } from '@/lib/csrfToken';
import type { PanelProps } from '@/threads/panels';
import type { SharedProps } from '@/types/shared';
import type { FileContentData, FileEntryData, FileScope } from '@/types/thread-files';
import { type ChangedPaths } from './changeLetter';
import { ConflictCompare } from './ConflictCompare';
import { EditorNotices } from './EditorNotices';
import { FileBrowser } from './FileBrowser';
import { FileStates } from './FileStates';
import { useFileTree } from './useFileTree';
import { useOpenFile } from './useOpenFile';

const FileEditor = lazy(() => import('./FileEditor'));

const SCOPES: { id: FileScope; label: string }[] = [
    { id: 'repo', label: 'Repository' },
    { id: 'sandbox', label: 'Whole sandbox' },
];

/** The Everything side of the Files view: the repository and sandbox tree beside the editor. */
export default function FilesPanel({ thread, canDrive, diff = null, focusRequest = null, modeSwitch, onOpenChanges }: PanelProps) {
    const [scope, setScope] = useState<FileScope>('repo');
    const tree = useFileTree(thread.id, scope);
    const files = useOpenFile(thread.id);
    const active = files.openFiles.find((file) => file.path === files.activePath) ?? null;
    const [drafts, setDrafts] = useState<Record<string, string>>({});
    const [conflicts, setConflicts] = useState<Record<string, FileContentData>>({});
    const [comparing, setComparing] = useState<Record<string, boolean>>({});
    const [savingPath, setSavingPath] = useState<string | null>(null);
    const scopes = canDrive ? SCOPES : SCOPES.filter((entry) => entry.id === 'repo');
    const working = thread.runnerState === 'busy';
    const docsUrl = usePage<SharedProps>().props.docs.baseUrl;
    const [isNoticeDismissed, setIsNoticeDismissed] = useState(false);
    const [savedNote, setSavedNote] = useState<string | null>(null);

    const save = async (path: string, overwrite = false) => {
        const file = files.openFiles.find((entry) => entry.path === path);
        if (!file) {
            return;
        }
        const content = drafts[path] ?? file.content ?? '';
        setSavingPath(path);
        try {
            const response = await fetch(`/threads/${thread.id}/files/content`, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() },
                body: JSON.stringify({ scope, path, content, base_sha256: file.baseSha256, overwrite }),
            });
            const body = (await response.json().catch(() => ({}))) as { state?: string; file?: FileContentData };
            if (response.status === 200 && body.file) {
                files.rebase(path, { ...body.file, content });
                setSavedNote(working ? `You edited ${path}. Claude reads the change at its next step.` : null);
                setConflicts(({ [path]: _cleared, ...rest }) => rest);
                setComparing((current) => ({ ...current, [path]: false }));
            } else if (response.status === 409 && body.state === 'conflict' && body.file) {
                setConflicts((current) => ({ ...current, [path]: body.file as FileContentData }));
            }
        } finally {
            setSavingPath(null);
        }
    };

    const loadTheirs = (path: string) => {
        const theirs = conflicts[path];
        if (!theirs) {
            return;
        }
        files.rebase(path, theirs);
        setDrafts(({ [path]: _discarded, ...rest }) => rest);
        setConflicts(({ [path]: _cleared, ...rest }) => rest);
        setComparing((current) => ({ ...current, [path]: false }));
    };

    // The thread's stored diff and the sandbox's own tree each know some changed files; the badge shows both.
    const changed = useMemo<ChangedPaths>(() => {
        const diffFiles = scope === 'repo' && diff !== null ? diff.files.filter((file) => file.repo === thread.repo) : [];

        return {
            changedPaths: [...new Set([...tree.changedPaths, ...diffFiles.filter((file) => file.status !== 'added').map((file) => file.path)])],
            addedPaths: [...new Set([...tree.addedPaths, ...diffFiles.filter((file) => file.status === 'added').map((file) => file.path)])],
        };
    }, [scope, diff, thread.repo, tree.changedPaths, tree.addedPaths]);

    useEffect(() => {
        if (focusRequest !== null) {
            setScope('repo');
            files.open('repo', focusRequest.path);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [focusRequest]);

    const unavailable = tree.state !== 'ready' ? tree.state : null;

    return (
        <div className="flex min-h-0 flex-1" data-testid="files-panel">
            <div className="flex w-[250px] shrink-0 flex-col border-r border-hair bg-sidebar">
                {modeSwitch !== undefined && <div className="mx-3 mt-3">{modeSwitch}</div>}
                <div role="tablist" aria-label="File scope" className="m-3 flex gap-1 rounded-lg bg-app p-1">
                    {scopes.map((entry) => (
                        <button
                            key={entry.id}
                            type="button"
                            role="tab"
                            aria-selected={entry.id === scope}
                            data-testid={`files-scope-${entry.id}`}
                            onClick={() => setScope(entry.id)}
                            className={cn('h-7 flex-1 rounded-md text-[13px]', entry.id === scope ? 'bg-sidebar font-medium text-body shadow-sm' : 'text-muted')}
                        >
                            {entry.label}
                        </button>
                    ))}
                </div>
                {unavailable === null && <FileBrowser repoName={thread.repo.split('/').pop() ?? thread.repo} tree={tree} changed={changed} activePath={files.activePath} onOpen={(entry: FileEntryData) => files.open(scope, entry.path)} />}
                {scope === 'sandbox' && unavailable === null && (
                    <p className="border-t border-hair px-3 py-2 text-[12px] text-muted">System folders are read only. You can change anything under your home folder and the workspace.</p>
                )}
            </div>
            <div className="flex min-w-0 flex-1 flex-col">
                {unavailable !== null ? (
                    <FileStates state={unavailable} canDrive={canDrive} thread={thread} canRestore={tree.detail?.canRestore} restoring={tree.detail?.restoring} waitingForSlot={tree.detail?.waitingForSlot} />
                ) : active === null ? (
                    <div className="flex flex-1 flex-col items-center justify-center gap-3 px-8 text-center" data-testid="files-empty">
                        <div className="flex size-12 items-center justify-center rounded-full bg-sidebar text-muted">
                            <FileCode2 size={20} />
                        </div>
                        <h3 className="text-[14px] font-semibold text-body">Pick a file to open it</h3>
                        <p className="max-w-sm text-[13px] text-muted">
                            The browser shows the whole sandbox, not only the repository. Files Claude changed in this thread have a dot. Edits you save are shown to Claude at its next step.
                        </p>
                        <a href={docsUrl} target="_blank" rel="noreferrer" className="text-[12.5px] underline" data-testid="files-docs-link">
                            What you can do here
                        </a>
                    </div>
                ) : (
                    <>
                        {!canDrive && <FileStates state="read_only_viewer" canDrive={canDrive} thread={thread} />}
                        {active.isTooLarge ? (
                            <FileStates state="too_large" canDrive={canDrive} thread={thread} file={{ name: active.path.split('/').pop() ?? active.path, size: active.size }} />
                        ) : active.isBinary ? (
                            <FileStates state="binary" canDrive={canDrive} thread={thread} />
                        ) : (
                            <>
                                <div className="flex items-center gap-3 border-b border-hair px-4 py-2">
                                    <p className="flex min-w-0 flex-1 items-center gap-1 truncate font-mono text-[12px] text-muted" data-testid="files-open-path">
                                        {active.path.split('/').map((segment, index) => (
                                            <span key={index} className="flex items-center gap-1">
                                                {index > 0 && <ChevronRight size={11} aria-hidden />}
                                                {segment}
                                            </span>
                                        ))}
                                    </p>
                                    {onOpenChanges !== undefined && (changed.changedPaths.includes(active.path) || changed.addedPaths.includes(active.path)) && (
                                        <Button variant="secondary" className="h-7" icon={<FileDiff size={12} />} onClick={() => onOpenChanges(active.path)} data-testid="files-view-diff">
                                            View diff
                                        </Button>
                                    )}
                                    {active.canEdit && (
                                        <>
                                            <span className="text-[12px] text-muted">Cmd+S</span>
                                            <Button
                                                variant="primary"
                                                data-testid="file-save"
                                                disabled={(drafts[active.path] ?? active.content) === active.content || savingPath === active.path}
                                                onClick={() => save(active.path)}
                                            >
                                                {savingPath === active.path ? 'Saving' : 'Save'}
                                            </Button>
                                        </>
                                    )}
                                </div>
                                <div role="tablist" className="flex gap-1 overflow-x-auto border-b border-hair px-2 pt-2" data-testid="files-open-tabs">
                                    {files.openFiles.map((file) => (
                                        <div key={file.path} className={cn('flex h-7 items-center rounded-t-md text-[12px]', file.path === active.path ? 'bg-sidebar font-medium text-body' : 'text-muted')}>
                                            <button type="button" role="tab" aria-selected={file.path === active.path} onClick={() => files.setActive(file.path)} className="flex h-7 items-center gap-1.5 pr-1 pl-2.5">
                                                {file.path.split('/').pop()}
                                                {(drafts[file.path] ?? file.content) !== file.content && <span className="size-1.5 rounded-full bg-primary" data-testid="file-unsaved-dot" />}
                                            </button>
                                            <button type="button" aria-label={`Close ${file.path}`} data-testid="file-tab-close" onClick={() => files.close(file.path)} className="flex size-5 items-center justify-center rounded text-muted">
                                                <X size={11} />
                                            </button>
                                        </div>
                                    ))}
                                </div>
                                {!isNoticeDismissed && <EditorNotices working={working} hasConflict={conflicts[active.path] !== undefined} onDismiss={() => setIsNoticeDismissed(true)} />}
                                {savedNote !== null && (
                                    <p className="border-b border-hair bg-sidebar px-4 py-2 text-[12px] text-muted" data-testid="editor-saved-note">
                                        {savedNote}
                                    </p>
                                )}
                                {conflicts[active.path] && (
                                    <div className="flex flex-wrap items-center gap-2 border-b border-hair bg-warn-soft px-4 py-3 text-[12px]" role="alert" data-testid="editor-conflict-banner">
                                        <span className="mr-auto min-w-0 text-body">
                                            Claude saved {active.path.split('/').pop()} after you opened it. Your edit is still here, nothing was overwritten. Saving yours replaces Claude's change.
                                        </span>
                                        <Button variant="primary" data-testid="conflict-save-mine" onClick={() => save(active.path, true)}>
                                            Save mine anyway
                                        </Button>
                                        <Button variant="secondary" data-testid="conflict-compare-btn" onClick={() => setComparing((current) => ({ ...current, [active.path]: !current[active.path] }))}>
                                            Compare the two
                                        </Button>
                                        <Button variant="secondary" onClick={() => loadTheirs(active.path)}>
                                            Load Claude's version
                                        </Button>
                                    </div>
                                )}
                                {comparing[active.path] && conflicts[active.path] ? (
                                    <ConflictCompare mine={drafts[active.path] ?? active.content ?? ''} theirs={conflicts[active.path].content ?? ''} />
                                ) : (
                                    <Suspense fallback={<div className="flex-1 p-4 text-[12px] text-muted">Loading editor</div>}>
                                        <FileEditor
                                            file={active}
                                            draft={drafts[active.path] ?? active.content ?? ''}
                                            canEdit={active.canEdit}
                                            onDraftChange={(content) => setDrafts((current) => ({ ...current, [active.path]: content }))}
                                            onSave={() => save(active.path)}
                                        />
                                    </Suspense>
                                )}
                            </>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}
