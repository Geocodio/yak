import type { PendingVisit } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { createElement, useEffect, useRef, useState, type ReactNode } from 'react';
import { ConfirmDialog } from '@geocodio/console-ui';

/**
 * While `isDirty`, asks before a GET visit to another page and before the tab
 * closes. Saves (non-GET), prefetches and reloads of the current page pass
 * through. A confirmed visit is issued again with every option it had.
 *
 * Browser back and forward are not guarded: Inertia handles `popstate` without
 * firing `before`, so only link clicks and `router.visit` calls reach the dialog.
 * The tab close prompt still covers leaving the site.
 */
export function useLeaveGuard(isDirty: boolean): { dialog: ReactNode } {
    const [pending, setPending] = useState<PendingVisit | null>(null);
    const isConfirmedRef = useRef(false);

    useEffect(() => {
        if (!isDirty) {
            return;
        }

        const removeBefore = router.on('before', (event) => {
            const { visit } = event.detail;

            if (isConfirmedRef.current || visit.prefetch || visit.method !== 'get' || visit.url.pathname === window.location.pathname) {
                return;
            }

            setPending(visit);

            return false;
        });
        const warnBeforeUnload = (event: BeforeUnloadEvent) => event.preventDefault();
        window.addEventListener('beforeunload', warnBeforeUnload);

        return () => {
            removeBefore();
            window.removeEventListener('beforeunload', warnBeforeUnload);
        };
    }, [isDirty]);

    const leave = () => {
        if (pending === null) {
            return;
        }

        // `before` fires synchronously inside `visit`, so the flag only covers this one visit.
        isConfirmedRef.current = true;

        try {
            router.visit(pending.url, pending);
        } finally {
            isConfirmedRef.current = false;
        }

        setPending(null);
    };

    const dialog = createElement(ConfirmDialog, {
        open: pending !== null,
        onOpenChange: (open: boolean) => !open && setPending(null),
        title: 'Leave without saving?',
        body: 'This section has changes that are not saved. They are lost if you leave.',
        confirmLabel: 'Leave',
        destructive: true,
        confirmTestId: 'leave-guard-confirm',
        onConfirm: leave,
    });

    return { dialog };
}
