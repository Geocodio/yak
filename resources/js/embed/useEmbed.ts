import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/shared';

/** Set while the phone's web view shows the page: only the conversation and its cards are drawn. */
export function useEmbed(): SharedProps['embed'] {
    return usePage<SharedProps>().props.embed;
}
