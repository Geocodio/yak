import type { ThreadDiffFile } from '@/types/thread-changes';

export type TreeNode =
    | { kind: 'directory'; name: string; path: string; children: TreeNode[] }
    | { kind: 'file'; name: string; path: string; key: string; file: ThreadDiffFile };

/** Identifies a file across repositories. The primary repository's files keep their plain path. */
export function fileKey(file: ThreadDiffFile, primaryRepo: string): string {
    return file.repo === primaryRepo ? file.path : `${file.repo}:${file.path}`;
}

/**
 * Nests files under their directories; directories sort before files, each by name. Single-child chains stay expanded.
 * With `groupByRepo` each repository is the top-level directory.
 */
export function buildFileTree(files: ThreadDiffFile[], primaryRepo: string, groupByRepo = false): TreeNode[] {
    const root: TreeNode[] = [];

    for (const file of files) {
        const segments = groupByRepo ? [file.repo, ...file.path.split('/')] : file.path.split('/');
        let level = root;

        segments.slice(0, -1).forEach((name, index) => {
            const path = segments.slice(0, index + 1).join('/');
            let directory = level.find((node) => node.kind === 'directory' && node.name === name);
            if (directory === undefined) {
                directory = { kind: 'directory', name, path, children: [] };
                level.push(directory);
            }
            level = (directory as Extract<TreeNode, { kind: 'directory' }>).children;
        });

        level.push({ kind: 'file', name: segments[segments.length - 1], path: file.path, key: fileKey(file, primaryRepo), file });
    }

    const sort = (nodes: TreeNode[]): TreeNode[] => {
        nodes.sort((a, b) => (a.kind === b.kind ? a.name.localeCompare(b.name) : a.kind === 'directory' ? -1 : 1));
        nodes.forEach((node) => node.kind === 'directory' && sort(node.children));
        return nodes;
    };

    return sort(root);
}
