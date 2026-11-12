import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/shared';

/** Joins the docs site base URL and a path relative to it, such as `teleport/#sign-in`. */
export function docsUrl(baseUrl: string, path: string): string {
    return `${baseUrl.replace(/\/+$/, '')}/${path.replace(/^\/+/, '')}`;
}

export function useDocsUrl(path: string): string {
    return docsUrl(usePage<SharedProps>().props.docs.baseUrl, path);
}
