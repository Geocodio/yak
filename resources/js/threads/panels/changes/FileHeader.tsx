import { Button, cn } from '@geocodio/console-ui';
import { ChevronDown, ChevronRight, Folder } from 'lucide-react';
import { CopyButton } from '@/components/threads/environment/LinkActions';
import type { ThreadDiffFile } from '@/types/thread-changes';

type Props = {
    file: ThreadDiffFile;
    label: string;
    isCollapsed: boolean;
    onToggleCollapsed: () => void;
    isViewed: boolean;
    onToggleViewed: () => void;
    /** Opens the file in Files; absent when the file cannot be opened there. */
    onOpenInFiles?: () => void;
    isNarrow: boolean;
};

/** The top of one file's diff card: collapse, path, change count, copy, Viewed, and Open in Files. */
export function FileHeader({ file, label, isCollapsed, onToggleCollapsed, isViewed, onToggleViewed, onOpenInFiles, isNarrow }: Props) {
    const additions = file.additions ?? 0;
    const deletions = file.deletions ?? 0;

    return (
        <div className="flex flex-col">
            <div className={cn('flex items-center gap-2 bg-app px-2 py-1.5 text-[12.5px]', !isCollapsed && 'border-b border-hair')} data-testid="changes-file-header" data-collapsed={isCollapsed}>
                <button type="button" onClick={onToggleCollapsed} aria-expanded={!isCollapsed} aria-label={isCollapsed ? 'Expand file' : 'Collapse file'} className="inline-flex size-[26px] shrink-0 cursor-pointer items-center justify-center rounded-[6px] text-muted hover:bg-panel-2 hover:text-body">
                    {isCollapsed ? <ChevronRight size={13} /> : <ChevronDown size={13} />}
                </button>
                <code className="min-w-0 flex-1 truncate font-mono text-[12px] text-body" title={file.path}>{isNarrow ? label.split('/').pop() : label}</code>
                <span className="inline-flex shrink-0 gap-1.5 font-mono text-[12px] font-medium tabular-nums">
                    <span className="text-ok">+{additions}</span>
                    <span className="text-fail">-{deletions}</span>
                </span>
                {!isNarrow && <CopyButton text={file.path} label="file path" testId="changes-copy-path" />}
                {!isNarrow && (
                    <label className="flex shrink-0 cursor-pointer items-center gap-1.5 text-[12px] text-muted">
                        <input type="checkbox" checked={isViewed} onChange={onToggleViewed} />
                        Viewed
                    </label>
                )}
                {onOpenInFiles !== undefined && (
                    <Button variant="secondary" className="h-7 shrink-0" icon={<Folder size={12} />} onClick={onOpenInFiles} data-testid="changes-open-in-files">
                        {isNarrow ? 'Files' : 'Open in Files'}
                    </Button>
                )}
            </div>
            {file.status === 'renamed' && file.oldPath !== null && (
                <p className="border-b border-hair px-3 py-1 text-[12px] text-muted" data-testid="changes-renamed-banner">
                    Renamed from {file.oldPath}
                </p>
            )}
        </div>
    );
}
