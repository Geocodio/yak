import { cn } from '@geocodio/console-ui';
import { ChevronDown, ChevronRight, FileText, Folder } from 'lucide-react';
import { useState } from 'react';
import type { ThreadDiffFile } from '@/types/thread-changes';
import type { TreeNode } from './buildFileTree';

const STATUS_LETTER: Record<ThreadDiffFile['status'], string> = { added: 'A', modified: 'M', deleted: 'D', renamed: 'R' };
const STATUS_TONE: Record<ThreadDiffFile['status'], string> = { added: 'text-ok', modified: 'text-info', deleted: 'text-fail', renamed: 'text-warn' };

type Props = {
    nodes: TreeNode[];
    viewed: Set<string>;
    onToggleViewed: (path: string) => void;
    onOpen: (path: string) => void;
    depth?: number;
    showStatusBadge?: boolean;
};

export function FileTree({ nodes, viewed, onToggleViewed, onOpen, depth = 0, showStatusBadge = false }: Props) {
    return (
        <ul>
            {nodes.map((node) =>
                node.kind === 'directory' ? (
                    <DirectoryRow key={node.path} node={node} viewed={viewed} onToggleViewed={onToggleViewed} onOpen={onOpen} depth={depth} showStatusBadge={showStatusBadge} />
                ) : (
                    <li key={node.key} className="flex items-center gap-2 py-1" style={{ paddingLeft: depth * 12 }} data-testid={`changes-file-${node.path}`}>
                        <input type="checkbox" checked={viewed.has(node.key)} onChange={() => onToggleViewed(node.key)} aria-label={`Mark ${node.path} viewed`} className="shrink-0" />
                        <button type="button" onClick={() => onOpen(node.key)} className="flex min-w-0 flex-1 cursor-pointer items-center gap-1.5 text-left text-[13px] text-body hover:underline">
                            <FileText size={13} className="shrink-0 text-muted" />
                            <span className={cn('truncate', viewed.has(node.key) && 'text-muted')}>{node.name}</span>
                        </button>
                        <span className="shrink-0 text-[11.5px] tabular-nums text-muted">
                            {node.file.isBinary || node.file.isTooLarge ? (
                                node.file.isBinary ? 'binary' : 'too large'
                            ) : (
                                <>
                                    <span className="text-ok">+{node.file.additions ?? 0}</span> <span className="text-fail">-{node.file.deletions ?? 0}</span>
                                </>
                            )}
                        </span>
                        <span className={cn('shrink-0 text-[11px] font-medium', showStatusBadge ? 'rounded-chip bg-panel-2 px-1.5' : 'w-3', STATUS_TONE[node.file.status])}>{STATUS_LETTER[node.file.status]}</span>
                    </li>
                ),
            )}
        </ul>
    );
}

function DirectoryRow({ node, viewed, onToggleViewed, onOpen, depth, showStatusBadge }: Omit<Props, 'nodes' | 'depth'> & { depth: number; node: Extract<TreeNode, { kind: 'directory' }> }) {
    const [isOpen, setIsOpen] = useState(true);

    return (
        <li>
            <button type="button" onClick={() => setIsOpen(!isOpen)} className="flex w-full cursor-pointer items-center gap-1.5 py-1 text-left text-[13px] text-muted" style={{ paddingLeft: depth * 12 }} aria-expanded={isOpen}>
                {isOpen ? <ChevronDown size={13} /> : <ChevronRight size={13} />}
                <Folder size={13} />
                {node.name}
            </button>
            {isOpen && <FileTree nodes={node.children} viewed={viewed} onToggleViewed={onToggleViewed} onOpen={onOpen} depth={depth + 1} showStatusBadge={showStatusBadge} />}
        </li>
    );
}
