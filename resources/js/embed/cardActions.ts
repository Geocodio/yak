const CO_OWNER_ACTIONS = ['approve', 'request-changes', 'reject', 'withdraw', 'check-access', 'tag', 'decline'];
const THREAD_ACTIONS = ['retry', 'cancel', 'move', 'setup-and-continue', 'request-review', 'retry-render', 'continue'];

/** The card action a POST to this path is, or null when the request is not one: `answer`, `approve`, `retry`, and so on. */
export function cardActionOf(method: string, pathname: string): string | null {
    if (method.toLowerCase() !== 'post') {
        return null;
    }

    const match = /^\/(?:app\/embed\/)?threads\/[A-Za-z0-9]{8}\/(.+)$/.exec(pathname);

    if (match === null) {
        return null;
    }

    const rest = match[1];

    if (rest === 'clarification-answers' || rest === 'repository-answer') {
        return 'answer';
    }
    if (rest.startsWith('co-owner/') && CO_OWNER_ACTIONS.includes(rest.slice('co-owner/'.length))) {
        return rest.slice('co-owner/'.length);
    }
    if (rest === 'restore/retry') {
        return 'restore-retry';
    }

    return THREAD_ACTIONS.includes(rest) ? rest : null;
}
