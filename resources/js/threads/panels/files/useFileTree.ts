import { useCallback, useEffect, useState } from 'react';
import type { FileEntryData, FileScope, FileState, FileTreeResponse } from '@/types/thread-files';

/** Reads a JSON answer, keeping the body of 409 state answers (waking, destroyed, on_device). */
export async function fetchJson<T>(url: string, signal?: AbortSignal): Promise<{ status: number; body: T }> {
    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal });
    const body = (await response.json().catch(() => ({}))) as T;

    return { status: response.status, body };
}

export type FileTree = {
    entriesByDirectory: Record<string, FileEntryData[]>;
    root: string;
    changedPaths: string[];
    addedPaths: string[];
    state: FileState;
    detail: FileTreeResponse | null;
    isLoading: boolean;
    error: string | null;
    expand: (path: string) => void;
};

/** Loads one directory per expansion. The scope root is the empty path. */
export function useFileTree(threadId: number, scope: FileScope): FileTree {
    const [entriesByDirectory, setEntriesByDirectory] = useState<Record<string, FileEntryData[]>>({});
    const [root, setRoot] = useState('');
    const [changedPaths, setChangedPaths] = useState<string[]>([]);
    const [addedPaths, setAddedPaths] = useState<string[]>([]);
    const [state, setState] = useState<FileState>('ready');
    const [detail, setDetail] = useState<FileTreeResponse | null>(null);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const load = useCallback(
        (path: string) => {
            setIsLoading(true);
            const query = new URLSearchParams({ scope, path });
            fetchJson<FileTreeResponse>(`/threads/${threadId}/files?${query}`)
                .then(({ status, body }) => {
                    if (status === 200 && body.entries) {
                        setEntriesByDirectory((current) => ({ ...current, [path]: body.entries ?? [] }));
                        if (path === '') {
                            setRoot(body.root ?? '');
                            setChangedPaths(body.changedPaths ?? []);
                            setAddedPaths(body.addedPaths ?? []);
                        }
                        setState('ready');
                        setError(null);
                    } else if (status === 409 && body.state) {
                        setState(body.state);
                        setDetail(body);
                    } else {
                        setError(`Request failed (${status})`);
                    }
                })
                .catch(() => setError('Request failed'))
                .finally(() => setIsLoading(false));
        },
        [threadId, scope],
    );

    useEffect(() => {
        setEntriesByDirectory({});
        setState('ready');
        load('');
    }, [load]);

    const expand = useCallback(
        (path: string) => {
            if (entriesByDirectory[path] === undefined) {
                load(path);
            }
        },
        [entriesByDirectory, load],
    );

    return { entriesByDirectory, root, changedPaths, addedPaths, state, detail, isLoading, error, expand };
}
