import type { SharedFile } from '@/types/threads';

const KIND_LABELS: Record<SharedFile['kind'], string> = { report: 'Report', image: 'Image', csv: 'CSV', pdf: 'PDF', text: 'Text', file: 'File' };

export function kindLabel(file: SharedFile): string {
    return KIND_LABELS[file.kind];
}

/** Open for an HTML report (the viewer), View for an image (the lightbox), Download for the rest. */
export function openAction(file: SharedFile): 'open' | 'view' | 'download' {
    return file.kind === 'report' ? 'open' : file.kind === 'image' ? 'view' : 'download';
}

/** The link a person copies: the Yak page for the file, on this host. */
export function fileLink(file: SharedFile): string {
    return new URL(file.url, window.location.origin).toString();
}

/**
 * Where the shared-files cards belong. Files of one turn share an anchor (the thread event its turn ended at) and form
 * one card after the last event entry at or before it. Files with no anchor, or none of whose events are loaded, form
 * one card at the end. `after` is keyed by the entry's index.
 */
export function placeSharedFiles(entries: ReadonlyArray<{ type: string; item?: { sequence: number } }>, files: SharedFile[]): { after: Map<number, SharedFile[][]>; trailing: SharedFile[] } {
    const groups = new Map<number | null, SharedFile[]>();
    files.forEach((file) => groups.set(file.anchorSequence, [...(groups.get(file.anchorSequence) ?? []), file]));

    const after = new Map<number, SharedFile[][]>();
    const trailing: SharedFile[] = [];

    groups.forEach((group, anchor) => {
        let index = -1;

        entries.forEach((entry, position) => {
            if (anchor !== null && entry.type === 'event' && entry.item !== undefined && entry.item.sequence <= anchor) {
                index = position;
            }
        });

        if (index === -1) {
            trailing.push(...group);
        } else {
            after.set(index, [...(after.get(index) ?? []), group]);
        }
    });

    return { after, trailing };
}
