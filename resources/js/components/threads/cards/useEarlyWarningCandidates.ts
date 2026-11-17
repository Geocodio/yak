import { useCallback, useEffect, useState } from 'react';
import { candidates } from '@/routes/threads/co-owner';
import type { EarlyWarningCandidate } from '@/types/coOwner';

export type CandidatesState = { status: 'loading' } | { status: 'error' } | { status: 'ready'; items: EarlyWarningCandidate[]; isComplete: boolean };

/** Loads the people the requester can tag when the card opens; a failed load can be tried again. */
export function useEarlyWarningCandidates(threadId: string): { state: CandidatesState; reload: () => void } {
    const [state, setState] = useState<CandidatesState>({ status: 'loading' });
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        const controller = new AbortController();
        setState({ status: 'loading' });
        fetch(candidates.url(threadId), { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
            .then((body: { candidates: EarlyWarningCandidate[]; isComplete: boolean }) => setState({ status: 'ready', items: body.candidates, isComplete: body.isComplete }))
            .catch((error: unknown) => {
                if (!controller.signal.aborted) {
                    void error;
                    setState({ status: 'error' });
                }
            });

        return () => controller.abort();
    }, [threadId, attempt]);

    const reload = useCallback(() => setAttempt((value) => value + 1), []);

    return { state, reload };
}
