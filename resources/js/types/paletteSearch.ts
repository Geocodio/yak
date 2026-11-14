/** Mirrors the array shape documented on App\Http\Resources\PaletteSearchData. */
export type PaletteSearchThread = {
    id: string;
    title: string;
    repo: string;
    branch: string | null;
    status: 'open' | 'closed';
    previewUrl: string | null;
    pullRequestUrl: string | null;
    lastActivityAt: string | null;
};

export type PaletteSearchRepository = {
    slug: string;
    name: string;
    url: string;
    githubUrl: string | null;
    /** The new-thread dialog lists only active repositories. */
    isActive: boolean;
};

export type PaletteSearchPullRequest = {
    number: number;
    title: string;
    repo: string;
    url: string;
    threadId: string | null;
    reviewUrl: string | null;
};

export type PaletteSearchResults = {
    threads: PaletteSearchThread[];
    repositories: PaletteSearchRepository[];
    pullRequests: PaletteSearchPullRequest[];
};
