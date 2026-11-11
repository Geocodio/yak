import { Button } from '@geocodio/console-ui';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { fetchJsonWithRetry } from '@/lib/fetchJsonWithRetry';
import { csrfToken } from '@/lib/csrfToken';
import { show, update } from '@/routes/threads/share';
import type { ThreadShareState } from '@/types/thread-share';
import type { ThreadData } from '@/types/threads';

/**
 * The people a private thread is shared with, each with a Remove button. Remove only ever
 * narrows: it replays the remaining people, and the last removal sends only_me.
 */
export function SharedPeopleList({ thread }: { thread: ThreadData }) {
    const [state, setState] = useState<ThreadShareState | null>(null);
    const [pendingId, setPendingId] = useState<number | null>(null);
    const [hasRemoveFailed, setHasRemoveFailed] = useState(false);

    const load = useCallback(() => {
        fetchJsonWithRetry<ThreadShareState>(show.url(thread.id))
            .then(setState)
            .catch(() => setState(null));
    }, [thread.id]);

    useEffect(load, [load, thread.sharedWithCount, thread.visibility]);

    const shared = state === null || state.mode !== 'people' ? [] : state.people.filter((person) => state.userIds.includes(person.id));

    if (shared.length === 0) {
        return null;
    }

    const remove = (id: number) => {
        const remaining = shared.filter((person) => person.id !== id).map((person) => person.id);

        setPendingId(id);
        setHasRemoveFailed(false);
        fetch(update.url(thread.id), {
            method: 'PUT',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ mode: remaining.length === 0 ? 'only_me' : 'people', user_ids: remaining }),
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                router.reload({ only: ['thread', 'publishWarning'], onFinish: load });
            })
            .catch(() => setHasRemoveFailed(true))
            .finally(() => setPendingId(null));
    };

    return (
        <div className="mt-2 flex flex-col gap-1" data-testid="shared-people">
            {shared.map((person) => (
                <div key={person.id} className="flex items-center justify-between gap-2 text-[12.5px]">
                    <span className="truncate">{person.name}</span>
                    <Button variant="tertiary" disabled={pendingId !== null} pending={pendingId === person.id} onClick={() => remove(person.id)} data-testid={`share-remove-${person.id}`}>Remove</Button>
                </div>
            ))}
            {hasRemoveFailed && <p role="alert" data-testid="share-remove-error" className="text-[12px] text-fail">Could not remove that person. Nothing changed.</p>}
        </div>
    );
}
