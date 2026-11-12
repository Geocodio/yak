import { cn } from '@geocodio/console-ui';
import { ChevronDown, ChevronRight, File, Folder, FolderOpen, Lock } from 'lucide-react';
import type { FileEntryData } from '@/types/thread-files';

type Props = {
    entry: FileEntryData;
    depth: number;
    isExpanded: boolean;
    isActive: boolean;
    changeLetter: 'M' | 'A' | null;
    isRepository?: boolean;
    onToggle: (entry: FileEntryData) => void;
    onOpen: (entry: FileEntryData) => void;
};

export function FileTreeNode({ entry, depth, isExpanded, isActive, changeLetter, isRepository, onToggle, onOpen }: Props) {
    const Icon = entry.isDirectory ? (isExpanded ? FolderOpen : Folder) : File;

    return (
        <button
            type="button"
            data-testid="files-tree-item"
            data-path={entry.path}
            onClick={() => (entry.isDirectory ? onToggle(entry) : onOpen(entry))}
            style={{ paddingLeft: 8 + depth * 14 }}
            className={cn('flex h-8 w-full items-center gap-1.5 pr-2 text-left text-[13px] text-body', isActive && 'bg-app font-medium')}
        >
            <span className="flex w-3 shrink-0 text-muted">{entry.isDirectory ? isExpanded ? <ChevronDown size={12} /> : <ChevronRight size={12} /> : null}</span>
            <Icon size={14} className="shrink-0 text-muted" />
            <span className="min-w-0 flex-1 truncate">{entry.name}</span>
            {isRepository && (
                <span className="rounded bg-app px-1.5 text-[10px] font-medium text-muted" data-testid="files-repo-tag">
                    Repository
                </span>
            )}
            {changeLetter !== null && (
                <span className="flex items-center gap-1 text-[11px] font-medium text-muted" data-testid={`file-changed-${entry.path}`}>
                    <span className="size-1.5 rounded-full bg-accent" aria-hidden />
                    {changeLetter}
                </span>
            )}
            {entry.isDirectory && !entry.isWritable && <Lock size={11} className="shrink-0 text-muted" aria-label="Read only" />}
        </button>
    );
}
