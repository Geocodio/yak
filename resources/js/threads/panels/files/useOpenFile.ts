import { useCallback, useState } from 'react';
import type { FileContentData, FileScope, FileState, OpenFileData } from '@/types/thread-files';
import { fetchJson } from './useFileTree';

type ShowResponse = FileContentData & { state: FileState; canEdit: boolean };

export type OpenFiles = {
    openFiles: OpenFileData[];
    activePath: string | null;
    failure: FileState | null;
    open: (scope: FileScope, path: string) => void;
    close: (path: string) => void;
    reload: (path: string) => void;
    setActive: (path: string) => void;
};

/** Open tabs of the Files panel. Each file keeps the sha256 it was opened with as `baseSha256`. */
export function useOpenFile(threadId: number): OpenFiles {
    const [openFiles, setOpenFiles] = useState<OpenFileData[]>([]);
    const [scopes, setScopes] = useState<Record<string, FileScope>>({});
    const [activePath, setActivePath] = useState<string | null>(null);
    const [failure, setFailure] = useState<FileState | null>(null);

    const fetchFile = useCallback(
        (scope: FileScope, path: string) => {
            const query = new URLSearchParams({ scope, path });
            fetchJson<ShowResponse>(`/threads/${threadId}/files/content?${query}`).then(({ status, body }) => {
                if (status !== 200) {
                    setFailure(body.state ?? null);
                    return;
                }
                setFailure(null);
                const file: OpenFileData = { ...body, baseSha256: body.sha256 };
                setOpenFiles((current) => (current.some((entry) => entry.path === path) ? current.map((entry) => (entry.path === path ? file : entry)) : [...current, file]));
            });
        },
        [threadId],
    );

    const open = useCallback(
        (scope: FileScope, path: string) => {
            setScopes((current) => ({ ...current, [path]: scope }));
            setActivePath(path);
            fetchFile(scope, path);
        },
        [fetchFile],
    );

    const close = useCallback((path: string) => {
        setOpenFiles((current) => current.filter((entry) => entry.path !== path));
        setActivePath((current) => (current === path ? null : current));
    }, []);

    const reload = useCallback((path: string) => fetchFile(scopes[path] ?? 'repo', path), [fetchFile, scopes]);

    return { openFiles, activePath, failure, open, close, reload, setActive: setActivePath };
}
