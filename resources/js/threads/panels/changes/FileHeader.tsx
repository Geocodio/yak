import { ChevronDown, ChevronRight, Copy } from 'lucide-react';
import type { ThreadDiffFile } from '@/types/thread-changes';
import { ChangeBar } from './ChangeBar';

type Props = {
    file: ThreadDiffFile;
    label: string;
    isCollapsed: boolean;
    onToggleCollapsed: () => void;
    isViewed: boolean;
    onToggleViewed: () => void;
    onCopyPath: () => void;
};

export function FileHeader({ file, label, isCollapsed, onToggleCollapsed, isViewed, onToggleViewed, onCopyPath }: Props) {
    const additions = file.additions ?? 0;
    const deletions = file.deletions ?? 0;

    return (
        <div className="flex flex-col gap-1">
            <div className="flex items-center gap-2" data-testid="changes-file-header">
                <button type="button" onClick={onToggleCollapsed} aria-expanded={!isCollapsed} aria-label={isCollapsed ? 'Expand file' : 'Collapse file'} className="cursor-pointer text-muted hover:text-body">
                    {isCollapsed ? <ChevronRight size={14} /> : <ChevronDown size={14} />}
                </button>
                <code className="min-w-0 flex-1 truncate font-mono text-[12px] text-body">{label}</code>
                <span className="shrink-0 text-[12px] tabular-nums">
                    <span className="text-ok">+{additions}</span> <span className="text-fail">-{deletions}</span>
                </span>
                <ChangeBar additions={additions} deletions={deletions} />
                <button type="button" onClick={onCopyPath} aria-label="Copy file path" className="shrink-0 cursor-pointer text-muted hover:text-body">
                    <Copy size={13} />
                </button>
                <label className="flex shrink-0 items-center gap-1.5 text-[12px] text-muted">
                    <input type="checkbox" checked={isViewed} onChange={onToggleViewed} />
                    Viewed
                </label>
            </div>
            {file.status === 'renamed' && file.oldPath !== null && (
                <p className="text-[12px] text-muted" data-testid="changes-renamed-banner">
                    Renamed from {file.oldPath}
                </p>
            )}
        </div>
    );
}
