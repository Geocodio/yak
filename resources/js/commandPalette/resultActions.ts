import { rankActions } from './score.ts';
import { threadDataActions } from './threadDataActions.ts';
import type { PaletteSearchPullRequest, PaletteSearchRepository, PaletteSearchThread } from '../types/paletteSearch.ts';
import type { PaletteAction, PaletteStep } from './types.ts';

/** What a result needs from the app: the thread page URL and the new-thread dialog. */
export type ResultEnvironment = { threadUrl: (threadId: string) => string; openNewThread: (options: { repository: string }) => void };

const moreStep = (title: string, actions: PaletteAction[]): PaletteStep => ({ title, placeholder: 'Search actions…', items: (query) => rankActions(query, actions) });

const openInNewWindow = (url: string): void => {
    window.open(url, '_blank', 'noopener');
};

export function threadResultAction(row: PaletteSearchThread, environment: ResultEnvironment): PaletteAction {
    const href = environment.threadUrl(row.id);

    return {
        id: `thread:${row.id}`,
        label: row.title,
        keywords: [],
        hint: row.status === 'closed' ? `${row.repo} · closed` : row.repo,
        href,
        more: moreStep(row.title, [{ id: 'thread-open', label: 'Open thread', keywords: [], href }, ...threadDataActions(row)]),
    };
}

export function repositoryResultAction(row: PaletteSearchRepository, environment: ResultEnvironment): PaletteAction {
    const githubUrl = row.githubUrl;

    return {
        id: `repository:${row.slug}`,
        label: row.name,
        keywords: [],
        hint: 'repository',
        href: row.url,
        more: moreStep(row.name, [
            { id: 'repository-new-thread', label: 'New thread in this repository', keywords: [], run: () => environment.openNewThread({ repository: row.slug }) },
            ...(githubUrl === null ? [] : [{ id: 'repository-open-github', label: 'Open on GitHub', keywords: [], hint: 'new window', run: () => openInNewWindow(githubUrl) }]),
        ]),
    };
}

export function pullRequestResultAction(row: PaletteSearchPullRequest, environment: ResultEnvironment): PaletteAction {
    const children: PaletteAction[] = [
        ...(row.threadId === null ? [] : [{ id: 'pull-request-go-thread', label: 'Go to its thread', keywords: [], href: environment.threadUrl(row.threadId) }]),
        ...(row.reviewUrl === null ? [] : [{ id: 'pull-request-go-review', label: 'Go to its review', keywords: [], href: row.reviewUrl }]),
    ];

    return {
        id: `pull-request:${row.repo}#${row.number}`,
        label: row.title,
        keywords: [],
        hint: `${row.repo} #${row.number}`,
        run: () => openInNewWindow(row.url),
        ...(children.length === 0 ? {} : { more: moreStep(row.title, children) }),
    };
}
