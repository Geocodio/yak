import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import type { ThreadEventState } from '../../types/thread-events';

const SETTLE_MS = 300;
const RETRY_MS = 3000;

/** One string that changes whenever the turn or the thread state behind the page props changes. */
export function livePropsSignature(threadId: string, events: Pick<ThreadEventState, 'turn' | 'thread'>): string {
    const { turn, thread } = events;

    return [threadId, turn?.turnId, turn?.state, thread?.status, thread?.sandboxState, thread?.runnerState, thread?.blockedReason, thread?.pullRequestOpening].join('|');
}

/**
 * The status pill, the composer and the panels render server props. A turn or thread event means those
 * props are stale, so this reloads them, and keeps trying while the link is down.
 */
export function useRefreshThreadProps(signature: string): void {
    const seen = useRef(signature);

    useEffect(() => {
        if (seen.current === signature) {
            return;
        }

        seen.current = signature;
        let timer = window.setTimeout(reload, SETTLE_MS);

        function reload(): void {
            const retry = (): false => {
                timer = window.setTimeout(reload, RETRY_MS);

                return false;
            };

            router.reload({ showProgress: false, onNetworkError: retry, onHttpException: () => false });
        }

        return () => window.clearTimeout(timer);
    }, [signature]);
}
