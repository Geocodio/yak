import { cn } from '@geocodio/console-ui';
import { ChevronDown, ChevronRight, Folder } from 'lucide-react';
import { useState } from 'react';
import type { TreeNode } from './buildFileTree';
import type { FileMark } from './compareDiffs';
import { StatusTile } from './StatusTile';

type Props = {
    nodes: TreeNode[];
    viewed: Set<string>;
    onOpen: (path: string) => void;
    depth?: number;
    showStatusBadge?: boolean;
    marks?: Map<string, FileMark>;
};

export function FileTree({ nodes, viewed, onOpen, depth = 0, marks }: Props) {
    return (
        <ul>
            {nodes.map((node) =>
                node.kind === 'directory' ? (
                    <DirectoryRow key={node.path} node={node} viewed={viewed} onOpen={onOpen} depth={depth} marks={marks} />
                ) : (
                    <li key={node.key} className={cn('flex items-center gap-2 rounded-[6px] py-1', marks?.has(node.key) && 'row-arrive')} style={{ paddingLeft: depth * 12 }} data-testid={`changes-file-${node.path}`}>
                        <button type="button" onClick={() => onOpen(node.key)} className="flex min-w-0 flex-1 cursor-pointer items-center gap-1.5 text-left text-[13px] text-body hover:underline">
                            <StatusTile status={node.file.status} />
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
                    </li>
                ),
            )}
        </ul>
    );
}

function DirectoryRow({ node, viewed, onOpen, depth, marks }: Omit<Props, 'nodes' | 'depth'> & { depth: number; node: Extract<TreeNode, { kind: 'directory' }> }) {
    const [isOpen, setIsOpen] = useState(true);

    return (
        <li>
            <button type="button" onClick={() => setIsOpen(!isOpen)} className="flex w-full cursor-pointer items-center gap-1.5 py-1 text-left text-[13px] text-muted" style={{ paddingLeft: depth * 12 }} aria-expanded={isOpen}>
                {isOpen ? <ChevronDown size={13} /> : <ChevronRight size={13} />}
                <Folder size={13} />
                {node.name}
            </button>
            {isOpen && <FileTree nodes={node.children} viewed={viewed} onOpen={onOpen} depth={depth + 1} marks={marks} />}
        </li>
    );
}
