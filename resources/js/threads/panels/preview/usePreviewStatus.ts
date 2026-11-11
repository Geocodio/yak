import { useCallback, useEffect, useState } from 'react';
import { csrfToken } from '@/lib/csrfToken';
import { heartbeat, restore, status as statusRoute, warm } from '@/routes/threads/preview';
import type { ThreadPreviewState } from '@/types/threads';
import { isTransitional } from './previewState';

const POLL_MILLISECONDS = 2_000;
const HEARTBEAT_MILLISECONDS = 60_000;
const WARM_MILLISECONDS = 10_000;

export type PreviewStatus = {
    state: ThreadPreviewState;
    url: string | null;
    log: string[];
    error: string | null;
};

type Initial = { state: ThreadPreviewState; url: string | null };

/**
 * The live state of one preview target. Polls the status endpoint every 2 s while the preview is waking (or a
 * restore was requested) and sends a heartbeat every minute while the panel is open on a ready preview.
 */
export function usePreviewStatus(threadId: number, initial: Initial): { status: PreviewStatus; restoring: boolean; requestRestore: () => void } {
    const [status, setStatus] = useState<PreviewStatus>({ ...initial, log: [], error: null });
    const [restoring, setRestoring] = useState(false);

    useEffect(() => {
        setStatus({ ...initial, log: [], error: null });
        setRestoring(false);
        // Reset only when the target changes; `initial` is the page's snapshot for that target.
    }, [threadId]);

    const shouldPoll = restoring || isTransitional(status.state);

    useEffect(() => {
        if (!shouldPoll) {
            return;
        }

        const controller = new AbortController();
        const poll = () => {
            fetch(statusRoute.url(threadId), { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal })
                .then((response) => (response.ok ? (response.json() as Promise<PreviewStatus>) : null))
                .then((body) => {
                    if (body !== null) {
                        setStatus(body);
                        if (restoring && body.state !== 'destroyed') {
                            setRestoring(false);
                        }
                    }
                })
                .catch(() => undefined);
        };
        const interval = setInterval(poll, POLL_MILLISECONDS);

        return () => {
            controller.abort();
            clearInterval(interval);
        };
    }, [threadId, shouldPoll, restoring]);

    const isWaking = isTransitional(status.state);

    useEffect(() => {
        if (!isWaking) {
            return;
        }

        const requestWarm = () => {
            void fetch(warm.url(threadId), { method: 'POST', credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': csrfToken(), Accept: 'application/json' } }).catch(() => undefined);
        };
        requestWarm();
        const interval = setInterval(requestWarm, WARM_MILLISECONDS);

        return () => clearInterval(interval);
    }, [threadId, isWaking]);

    const isReady = status.state === 'ready';

    useEffect(() => {
        if (!isReady) {
            return;
        }

        const beat = () => {
            if (document.visibilityState === 'visible') {
                void fetch(heartbeat.url(threadId), { method: 'POST', credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': csrfToken() } }).catch(() => undefined);
            }
        };
        beat();
        const interval = setInterval(beat, HEARTBEAT_MILLISECONDS);

        return () => clearInterval(interval);
    }, [threadId, isReady]);

    const requestRestore = useCallback(() => {
        setRestoring(true);
        void fetch(restore.url(threadId), { method: 'POST', credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': csrfToken(), Accept: 'application/json' } })
            .then((response) => {
                if (!response.ok) {
                    setRestoring(false);
                }
            })
            .catch(() => setRestoring(false));
    }, [threadId]);

    return { status, restoring, requestRestore };
}
