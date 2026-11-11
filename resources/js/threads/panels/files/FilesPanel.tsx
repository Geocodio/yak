import { cn } from '@geocodio/console-ui';
import { FileCode2 } from 'lucide-react';
import { lazy, Suspense, useState } from 'react';
import { csrfToken } from '@/lib/csrfToken';
import type { PanelProps } from '@/threads/panels';
import type { FileContentData, FileEntryData, FileScope } from '@/types/thread-files';
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

export default function FilesPanel({ thread, canDrive }: PanelProps) {
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

    const unavailable = tree.state !== 'ready' ? tree.state : null;

    return (
        <div className="flex min-h-0 flex-1" data-testid="files-panel">
            <div className="flex w-[250px] shrink-0 flex-col border-r border-hair bg-sidebar">
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
                {unavailable === null && <FileBrowser tree={tree} activePath={files.activePath} onOpen={(entry: FileEntryData) => files.open(scope, entry.path)} />}
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
                    </div>
                ) : (
                    <>
                        {!canDrive && <FileStates state="read_only_viewer" canDrive={canDrive} thread={thread} />}
                        {active.isTooLarge ? (
                            <FileStates state="too_large" canDrive={canDrive} thread={thread} />
                        ) : active.isBinary ? (
                            <FileStates state="binary" canDrive={canDrive} thread={thread} />
                        ) : (
                            <>
                                <p className="truncate border-b border-hair px-4 py-2 font-mono text-[12px] text-muted" data-testid="files-open-path">
                                    {active.path}
                                </p>
                                <div role="tablist" className="flex gap-1 overflow-x-auto border-b border-hair px-2 pt-2" data-testid="files-open-tabs">
                                    {files.openFiles.map((file) => (
                                        <button
                                            key={file.path}
                                            type="button"
                                            role="tab"
                                            aria-selected={file.path === active.path}
                                            onClick={() => files.setActive(file.path)}
                                            className={cn('flex h-7 items-center gap-1.5 rounded-t-md px-2.5 text-[12px]', file.path === active.path ? 'bg-sidebar font-medium text-body' : 'text-muted')}
                                        >
                                            {file.path.split('/').pop()}
                                            {(drafts[file.path] ?? file.content) !== file.content && <span className="size-1.5 rounded-full bg-primary" data-testid="file-unsaved-dot" />}
                                        </button>
                                    ))}
                                </div>
                                <EditorNotices working={working} hasConflict={conflicts[active.path] !== undefined} />
                                {conflicts[active.path] && (
                                    <div className="flex flex-wrap items-center gap-2 border-b border-hair bg-sidebar px-4 py-2 text-[12px]" role="alert" data-testid="editor-conflict-banner">
                                        <span className="mr-auto font-medium text-body">Claude changed this file while you were editing</span>
                                        <button type="button" className="h-7 rounded-md border border-hair px-2.5" onClick={() => save(active.path, true)}>
                                            Save mine anyway
                                        </button>
                                        <button type="button" data-testid="conflict-compare-btn" className="h-7 rounded-md border border-hair px-2.5" onClick={() => setComparing((current) => ({ ...current, [active.path]: !current[active.path] }))}>
                                            Compare the two
                                        </button>
                                        <button type="button" className="h-7 rounded-md border border-hair px-2.5" onClick={() => loadTheirs(active.path)}>
                                            Load Claude's version
                                        </button>
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
                                            isSaving={savingPath === active.path}
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
