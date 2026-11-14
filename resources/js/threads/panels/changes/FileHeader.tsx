import { Button, cn } from '@geocodio/console-ui';
import { ChevronDown, ChevronRight, Pencil } from 'lucide-react';
import { useRef } from 'react';
import { CopyButton } from '@/components/threads/environment/LinkActions';
import type { ThreadDiffFile } from '@/types/thread-changes';
import type { FileMark } from './compareDiffs';

type Props = {
    file: ThreadDiffFile;
    label: string;
    isCollapsed: boolean;
    onToggleCollapsed: () => void;
    isViewed: boolean;
    onToggleViewed: () => void;
    /** Opens the file in the editor; absent when the file cannot be opened there. */
    onEditFile?: () => void;
    isNarrow: boolean;
    /** Set when the latest turn added or changed this file; cleared once it is marked as viewed. */
    mark?: FileMark;
    /** Called once the pointer rests on the header, to load a collapsed file's diff before it opens. */
    onHoverPrefetch?: () => void;
};

const HOVER_PREFETCH_MILLISECONDS = 80;

/** The top of one file's diff card: collapse, path, change count, copy, Viewed, and Edit file. */
export function FileHeader({ file, label, isCollapsed, onToggleCollapsed, isViewed, onToggleViewed, onEditFile, isNarrow, mark, onHoverPrefetch }: Props) {
    const hoverTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const startHover = () => {
        if (onHoverPrefetch !== undefined && hoverTimer.current === null) {
            hoverTimer.current = setTimeout(onHoverPrefetch, HOVER_PREFETCH_MILLISECONDS);
        }
    };
    const endHover = () => {
        if (hoverTimer.current !== null) {
            clearTimeout(hoverTimer.current);
            hoverTimer.current = null;
        }
    };
    const additions = file.additions ?? 0;
    const deletions = file.deletions ?? 0;

    return (
        <div className="flex flex-col">
            <div className={cn('flex items-center gap-2 bg-app px-2 py-1.5 text-[12.5px]', !isCollapsed && 'border-b border-hair')} data-testid="changes-file-header" data-collapsed={isCollapsed} onPointerEnter={startHover} onPointerLeave={endHover}>
                <button type="button" onClick={onToggleCollapsed} aria-expanded={!isCollapsed} aria-label={isCollapsed ? 'Expand file' : 'Collapse file'} className="inline-flex size-[26px] shrink-0 cursor-pointer items-center justify-center rounded-[6px] text-muted hover:bg-panel-2 hover:text-body">
                    {isCollapsed ? <ChevronRight size={13} /> : <ChevronDown size={13} />}
                </button>
                <code className="min-w-0 flex-1 truncate font-mono text-[12px] text-body" title={file.path}>{isNarrow ? label.split('/').pop() : label}</code>
                {mark !== undefined && (
                    <span className={cn('shrink-0 rounded-chip px-1.5 py-px text-[11px] font-medium', mark === 'new' ? 'bg-ok-soft text-ok' : 'bg-accent-soft text-accent-text')} data-testid="changes-file-mark">
                        {mark === 'new' ? 'New' : 'Updated'}
                    </span>
                )}
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
                {onEditFile !== undefined && (
                    <Button variant="secondary" className="h-7 shrink-0" icon={<Pencil size={12} />} onClick={onEditFile} data-testid="changes-edit-file">
                        {isNarrow ? 'Edit' : 'Edit file'}
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
