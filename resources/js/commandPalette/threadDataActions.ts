import type { PaletteAction } from './types.ts';

export type ThreadActionData = {
    id: string;
    branch: string | null;
    previewUrl: string | null;
    previewTargets?: { title: string; url: string | null }[];
    pullRequestUrl: string | null;
};

const openInNewWindow = (url: string): void => {
    window.open(url, '_blank', 'noopener');
};

/** The toast helper loads on use, so this module stays importable without the UI package. */
function notify(message: string, kind: 'success' | 'error'): void {
    import('@geocodio/console-ui').then(({ toast }) => toast[kind](message));
}

function copy(text: string, success: string, failure: string): void {
    navigator.clipboard.writeText(text).then(
        () => notify(success, 'success'),
        () => notify(failure, 'error'),
    );
}

function openPreviewAction(thread: ThreadActionData): PaletteAction {
    const base: PaletteAction = { id: 'thread-open-preview', label: 'Open preview in a new window', keywords: ['preview', 'site', 'app', 'browser', 'url', 'website'], hint: 'new window', shortcut: 'openPreview' };
    if (thread.previewUrl === null) {
        return { ...base, unavailable: 'This thread has no preview yet' };
    }

    const targets = (thread.previewTargets ?? []).filter((target): target is { title: string; url: string } => target.url !== null);
    if (targets.length >= 2) {
        return {
            ...base,
            steps: {
                title: 'Open preview',
                placeholder: 'Pick a preview…',
                items: (query) =>
                    targets
                        .filter((target) => target.title.toLowerCase().includes(query.trim().toLowerCase()))
                        .map((target, index) => ({ id: `thread-open-preview-${index}`, label: target.title, keywords: [], hint: 'new window', run: () => openInNewWindow(target.url) })),
            },
        };
    }

    return { ...base, run: () => openInNewWindow(thread.previewUrl as string) };
}

/** The thread actions that need only the thread's own data. The thread page and the search results both use them. */
export function threadDataActions(thread: ThreadActionData): PaletteAction[] {
    const pullRequestUrl = thread.pullRequestUrl;
    const branch = thread.branch;

    return [
        openPreviewAction(thread),
        {
            id: 'thread-open-pull-request',
            label: 'Open pull request on GitHub',
            keywords: ['pr', 'pull', 'request', 'github', 'review'],
            hint: 'new window',
            shortcut: 'openPullRequest',
            ...(pullRequestUrl === null ? { unavailable: 'This thread has no pull request yet' } : { run: () => openInNewWindow(pullRequestUrl) }),
        },
        ...(branch === null ? [] : [{ id: 'thread-copy-branch', label: 'Copy branch name', keywords: ['git', 'clipboard'], run: () => copy(branch, 'Copied the branch name', 'Could not copy the branch name.') }]),
        {
            id: 'thread-copy-link',
            label: 'Copy thread link',
            keywords: ['url', 'clipboard'],
            run: () => copy(`${window.location.origin}/threads/${thread.id}`, 'Link copied', 'Could not copy the link'),
        },
    ];
}
