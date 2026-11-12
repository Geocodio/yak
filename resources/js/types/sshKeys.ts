/** Mirrors the array shape documented on App\Http\Resources\SshKeyData. */
export type SshKey = {
    id: number;
    name: string;
    type: string;
    fingerprint: string;
    source: 'github' | 'uploaded';
    addedAt: string;
};

/** Mirrors the array shape documented on App\Http\Resources\SshKeysPageData. */
export type SshKeysPageData = {
    githubKeys: SshKey[];
    uploadedKeys: SshKey[];
    github: {
        linked: boolean;
        login: string | null;
        profileUrl: string | null;
        status: 'ok' | 'empty' | 'unknown_account' | 'error' | 'pending' | string;
        isStale: boolean;
        checkedAt: string | null;
    };
    config: {
        snippet: string;
        command: 'yak ssh --config <thread id>';
        docsUrl: string;
        jumpHost: string;
    };
};
