/** The segments under `/threads/` that are pages of their own, not thread ids. */
const RESERVED_SEGMENTS = ['log', 'new', 'list'];

function pathOf(url: string): string {
    return url.split('?')[0].split('#')[0];
}

/** The public id of the thread a URL shows, or null for any other URL. */
export function threadIdOfUrl(url: string): string | null {
    const segment = /^\/threads\/([^/]+)/.exec(pathOf(url))?.[1] ?? null;

    return segment === null || RESERVED_SEGMENTS.includes(segment) ? null : segment;
}

/** True for the thread list home, a thread page and the new thread page; false for the thread log. */
export function isThreadsSectionUrl(url: string): boolean {
    const path = pathOf(url);

    return path === '/' || path === '/threads/new' || threadIdOfUrl(path) !== null;
}
