import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { RequestPayload, VisitOptions } from '@inertiajs/core';

type Method = 'post' | 'patch' | 'put' | 'delete';

/**
 * Runs a router mutation while tracking which action is in flight.
 *
 * Several buttons used to call `router.post(...)` straight from `onClick`
 * with no pending state, so a slow action -- re-running setup, rebuilding
 * every deployment, rendering a sample video -- gave no sign the click had
 * registered, and people clicked again.
 *
 * Pass a stable `key` per button and feed `isPending(key)` to the button's
 * `pending` prop.
 */
export function useRouterAction() {
    const [pendingKey, setPendingKey] = useState<string | null>(null);

    const run = (
        key: string,
        method: Method,
        url: string,
        data: RequestPayload = {},
        options: VisitOptions = {},
    ): void => {
        setPendingKey(key);

        const finish = options.onFinish;

        const visitOptions: VisitOptions = {
            preserveScroll: true,
            ...options,
            onFinish: (visit) => {
                setPendingKey((current) => (current === key ? null : current));
                finish?.(visit);
            },
        };

        if (method === 'delete') {
            router.delete(url, visitOptions);

            return;
        }

        router[method](url, data, visitOptions);
    };

    return {
        run,
        pendingKey,
        isPending: (key: string): boolean => pendingKey === key,
        busy: pendingKey !== null,
    };
}
