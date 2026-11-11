export type FileScope = 'repo' | 'sandbox';

export type FileState = 'ready' | 'waking' | 'on_device' | 'destroyed' | 'closed_destroyed';

export type FileEntryData = {
    name: string;
    path: string;
    isDirectory: boolean;
    isWritable: boolean;
    size: number | null;
};

export type FileContentData = {
    path: string;
    sha256: string;
    size: number;
    isBinary: boolean;
    isTooLarge: boolean;
    content: string | null;
    headContent: string | null;
    language: string;
};

/** `GET threads/{thread}/files`: ready answers carry entries, every other state is a 409 body. */
export type FileTreeResponse = {
    state: FileState;
    entries?: FileEntryData[];
    root?: string;
    changedPaths?: string[];
    addedPaths?: string[];
    canRestore?: boolean;
    waitingForSlot?: boolean;
    restoring?: boolean;
};

export type OpenFileData = FileContentData & { canEdit: boolean; baseSha256: string };
