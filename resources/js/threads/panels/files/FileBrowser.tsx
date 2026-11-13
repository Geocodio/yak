import { TextInput } from '@geocodio/console-ui';
import { Search } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import type { FileEntryData } from '@/types/thread-files';
import { changeLetterFor, type ChangedPaths } from './changeLetter';
import { FileTreeNode } from './FileTreeNode';
import type { FileTree } from './useFileTree';

type Props = {
    tree: FileTree;
    changed: ChangedPaths;
    activePath: string | null;
    onOpen: (entry: FileEntryData) => void;
    /** Folder name of the thread's repository, tagged in the whole-sandbox tree. */
    repoName: string;
};

export function FileBrowser({ tree, changed, activePath, onOpen, repoName }: Props) {
    const [expanded, setExpanded] = useState<Set<string>>(new Set());
    const [filter, setFilter] = useState('');

    const toggle = (entry: FileEntryData) => {
        setExpanded((current) => {
            const next = new Set(current);
            if (next.has(entry.path)) {
                next.delete(entry.path);
            } else {
                next.add(entry.path);
                tree.expand(entry.path);
            }
            return next;
        });
    };

    const needle = filter.trim().toLowerCase();

    const render = (directory: string, depth: number): ReactNode[] =>
        (tree.entriesByDirectory[directory] ?? []).flatMap((entry) => {
            if (needle !== '' && !entry.isDirectory && !entry.path.toLowerCase().includes(needle)) {
                return [];
            }
            const isOpen = entry.isDirectory && expanded.has(entry.path);

            return [
                <FileTreeNode
                    key={entry.path}
                    entry={entry}
                    depth={depth}
                    isExpanded={isOpen}
                    isActive={entry.path === activePath}
                    changeLetter={changeLetterFor(entry.path, changed)}
                    isRepository={entry.isDirectory && entry.path.endsWith(`/workspace/${repoName}`)}
                    onToggle={toggle}
                    onOpen={onOpen}
                />,
                ...(isOpen ? render(entry.path, depth + 1) : []),
            ];
        });

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div className="relative px-3 pb-2">
                <Search size={13} className="pointer-events-none absolute top-2.5 left-5 text-muted" />
                <TextInput value={filter} onChange={(event) => setFilter(event.target.value)} placeholder="Find a file" aria-label="Find a file" className="pl-7" data-testid="files-filter" />
            </div>
            <p className="truncate border-t border-hair px-3 py-1.5 font-mono text-[12px] text-muted" data-testid="files-root">
                {tree.root === '' ? '/' : tree.root}
            </p>
            <div className="min-h-0 flex-1 overflow-auto" data-testid="files-tree">
                {render('', 0)}
                {tree.isLoading && Object.keys(tree.entriesByDirectory).length === 0 && <p className="px-3 py-2 text-[13px] text-muted">Loading files</p>}
                {tree.error !== null && <p className="px-3 py-2 text-[13px] text-muted">{tree.error}</p>}
            </div>
        </div>
    );
}
