import { router } from '@inertiajs/react';
import { createElement, useEffect, useRef, useState, type ReactNode } from 'react';
import { ConfirmDialog } from '@geocodio/console-ui';

type PendingVisit = Parameters<typeof router.visit>;

/**
 * While `isDirty`, asks before a GET visit to another page and before the tab
 * closes. Saves (non-GET) and reloads of the current page pass through.
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

            if (isConfirmedRef.current || visit.method !== 'get' || visit.url.pathname === window.location.pathname) {
                return;
            }

            setPending([visit.url, { method: visit.method, data: visit.data, preserveScroll: visit.preserveScroll, preserveState: visit.preserveState }]);

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

        isConfirmedRef.current = true;
        router.visit(...pending);
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
