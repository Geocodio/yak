import { usePage } from '@inertiajs/react';
import { toast } from '@geocodio/console-ui';
import { useEffect } from 'react';
import type { SharedProps } from '@/types/shared';

export function FlashToasts() {
    const { flash } = usePage<SharedProps>().props;
    useEffect(() => {
        if (flash.success) {
            toast.success(flash.success);
        }
        if (flash.error) {
            toast.error(flash.error);
        }
    }, [flash.success, flash.error]);
    return null;
}
