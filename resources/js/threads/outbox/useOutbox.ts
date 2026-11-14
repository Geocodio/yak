import { router } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';
import { csrfToken } from '@/lib/csrfToken';
import { store as storeMessage } from '@/routes/threads/messages';
import { createOutbox, type PostOutcome, type UnsentMessage } from './outbox';

/** A stalled link holds a request open without failing it; past this the send counts as unreachable and is repeated. */
const SEND_TIMEOUT_MS = 30000;

async function post(threadId: string, message: UnsentMessage, signal: AbortSignal): Promise<PostOutcome> {
    const headers: Record<string, string> = { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' };
    let payload: BodyInit;

    // Files go as multipart, which the browser frames itself; plain text stays JSON.
    if (message.files.length > 0) {
        const form = new FormData();
        form.append('message', message.text);
        form.append('send_key', message.key);
        if (message.model !== null) {
            form.append('model', message.model);
        }
        message.files.forEach((file, index) => {
            form.append('attachments[]', file);
            form.append(`attachment_refs[${index}]`, message.refs[index] ?? '');
        });
        payload = form;
    } else {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify({ message: message.text, model: message.model, send_key: message.key });
    }

    const response = await fetch(storeMessage.url(threadId), {
        method: 'POST',
        headers,
        credentials: 'same-origin',
        body: payload,
        signal: AbortSignal.any([signal, AbortSignal.timeout(SEND_TIMEOUT_MS)]),
    });

    if (response.ok) {
        const body = (await response.json().catch(() => ({}))) as { redirect?: string };

        return { kind: 'sent', redirect: body.redirect };
    }

    if (response.status >= 500) {
        return { kind: 'unreachable' };
    }

    if (response.status === 419) {
        return { kind: 'refused', reason: 'Your session expired. Reload the page, then retry.' };
    }

    const body = (await response.json().catch(() => ({}))) as { message?: string };

    return { kind: 'refused', reason: body.message ?? 'You cannot send messages to this thread.' };
}

/** Brings the delivered message into the page props, when the person still looks at that thread. */
function onDelivered(threadId: string, outcome: { redirect?: string }): Promise<void> {
    if (!window.location.pathname.startsWith(`/threads/${threadId}`)) {
        return Promise.resolve();
    }

    return new Promise((resolve) => {
        const options = { showProgress: false, onNetworkError: (): false => false, onHttpException: (): false => false, onFinish: () => resolve() };

        if (outcome.redirect) {
            router.visit(outcome.redirect, options);
        } else {
            router.reload(options);
        }
    });
}

export const outbox = createOutbox({ post, onDelivered });

if (typeof window !== 'undefined') {
    window.addEventListener('online', () => outbox.retryWaiting());
}

export function useUnsentMessages(threadId: string): UnsentMessage[] {
    return useSyncExternalStore(outbox.subscribe, () => outbox.messages(threadId));
}
