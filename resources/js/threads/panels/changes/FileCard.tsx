import { cn } from '@geocodio/console-ui';
import type { ThreadDiffFile } from '@/types/thread-changes';
import { DiffViewer } from './DiffViewer';
import { FileHeader } from './FileHeader';

type Props = {
    file: ThreadDiffFile;
    label: string;
    fileKey: string;
    threadId: string;
    mode: 'unified' | 'split';
    isCollapsed: boolean;
    isActive: boolean;
    isViewed: boolean;
    canExpand: boolean;
    isNarrow: boolean;
    onToggleCollapsed: () => void;
    onToggleViewed: () => void;
    onOpenInFiles?: () => void;
};

/** One changed file: its header over its diff, collapsible. */
export function FileCard({ file, label, fileKey, threadId, mode, isCollapsed, isActive, isViewed, canExpand, isNarrow, onToggleCollapsed, onToggleViewed, onOpenInFiles }: Props) {
    return (
        <article className={cn('scroll-mt-3 overflow-hidden rounded-[10px] border bg-panel', isActive ? 'border-accent-edge' : 'border-hair')} data-testid="changes-file-card" data-file-key={fileKey}>
            <FileHeader file={file} label={label} isCollapsed={isCollapsed} onToggleCollapsed={onToggleCollapsed} isViewed={isViewed} onToggleViewed={onToggleViewed} onOpenInFiles={onOpenInFiles} isNarrow={isNarrow} />
            {!isCollapsed && <DiffViewer key={file.id} threadId={threadId} file={file} mode={mode} canExpand={canExpand} />}
        </article>
    );
}
