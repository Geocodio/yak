import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { NewThreadDialog } from '@/components/threads/start/NewThreadDialog';
import type { SharedProps } from '@/types/shared';

/** Mounted once in the app shell; opens the new-thread dialog on `yak:open-new-thread`, loading its data with a partial reload. */
export function NewThreadDialogHost() {
    const { newThread } = usePage<SharedProps>().props;
    const [open, setOpen] = useState(false);

    useEffect(() => {
        const show = () => {
            router.reload({ only: ['newThread'], onSuccess: () => setOpen(true) });
        };
        window.addEventListener('yak:open-new-thread', show);

        return () => window.removeEventListener('yak:open-new-thread', show);
    }, []);

    if (!newThread) {
        return null;
    }

    return <NewThreadDialog open={open} onOpenChange={setOpen} {...newThread} />;
}
