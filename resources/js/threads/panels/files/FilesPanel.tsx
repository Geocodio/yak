import { cn } from '@geocodio/console-ui';
import { FileCode2 } from 'lucide-react';
import { useState } from 'react';
import type { PanelProps } from '@/threads/panels';
import type { FileEntryData, FileScope } from '@/types/thread-files';
import { FileBrowser } from './FileBrowser';
import { FileStates } from './FileStates';
import { useFileTree } from './useFileTree';
import { useOpenFile } from './useOpenFile';

const SCOPES: { id: FileScope; label: string }[] = [
    { id: 'repo', label: 'Repository' },
    { id: 'sandbox', label: 'Whole sandbox' },
];

export default function FilesPanel({ thread, canDrive }: PanelProps) {
    const [scope, setScope] = useState<FileScope>('repo');
    const tree = useFileTree(thread.id, scope);
    const files = useOpenFile(thread.id);
    const active = files.openFiles.find((file) => file.path === files.activePath) ?? null;
    const scopes = canDrive ? SCOPES : SCOPES.filter((entry) => entry.id === 'repo');
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
                                <pre className="min-h-0 flex-1 overflow-auto p-4 font-mono text-[12px] text-body" data-testid="files-viewer">
                                    {active.content}
                                </pre>
                            </>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}
