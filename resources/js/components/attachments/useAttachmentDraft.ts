import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState, type ChangeEvent, type ClipboardEvent, type DragEvent } from 'react';
import { formatBytes } from '@/lib/format';
import type { SharedProps } from '@/types/shared';
import { previewKind as previewKindOf, type PreviewKind } from './previewKind';

export type DraftAttachment = {
    key: string;
    file: File;
    /** How the message text refers to it, e.g. `Image #1`. Numbers count up across the draft and are never reused. */
    ref: string;
    /** Object URL for an image thumbnail; null for every other file type. */
    previewUrl: string | null;
    /** How the lightbox can show it, and the object URL it shows; both null when it can only be downloaded. */
    previewKind: PreviewKind | null;
    objectUrl: string | null;
};

/** Types both the browser and Claude render as images. Mirrors `TaskAttachment::IMAGE_MIME_TYPES`. */
const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

/** Clipboard images arrive as `image.png`; give them a name worth keeping. */
const GENERIC_CLIPBOARD_NAME = /^image\.(png|jpe?g|gif|webp)$/i;

let nextKey = 0;

function labelNumber(item: DraftAttachment): number {
    return Number(item.ref.split('#')[1]);
}

/**
 * The first candidate whose file has exactly the same contents, checking
 * size and type before reading any bytes.
 */
async function findSameFile<T extends { file: File }>(file: File, candidates: T[]): Promise<T | null> {
    let bytes: Uint8Array | null = null;

    for (const candidate of candidates) {
        if (candidate.file.size !== file.size || candidate.file.type !== file.type) {
            continue;
        }

        bytes ??= new Uint8Array(await file.arrayBuffer());
        const other = new Uint8Array(await candidate.file.arrayBuffer());

        if (bytes.every((byte, index) => byte === other[index])) {
            return candidate;
        }
    }

    return null;
}

function hasFiles(event: DragEvent | globalThis.DragEvent): boolean {
    return Array.from(event.dataTransfer?.types ?? []).includes('Files');
}

function renamePastedImage(file: File): File {
    if (!GENERIC_CLIPBOARD_NAME.test(file.name)) {
        return file;
    }

    const stamp = new Date().toISOString().slice(0, 19).replace('T', '-').replace(/:/g, '');
    const extension = file.type.split('/')[1]?.replace('jpeg', 'jpg') ?? 'png';

    return new File([file], `pasted-image-${stamp}.${extension}`, { type: file.type, lastModified: file.lastModified });
}

/**
 * Draft attachments for a message composer: paste, drag-and-drop and a
 * file picker all feed one list, checked against the server's limits
 * before anything is uploaded. Files go up with the message itself.
 *
 * While `enabled`, a file dragged anywhere over the window flags
 * `isDraggingFiles` (so the dropzone can announce itself) and a drop that
 * misses the dropzone is swallowed instead of navigating to the file.
 */
export function useAttachmentDraft({
    enabled = true,
    firstNumber = 1,
    onAdd,
}: {
    enabled?: boolean;
    /** Lowest label number this draft may use, so labels never repeat across a conversation. */
    firstNumber?: number;
    /** Called with each batch of newly accepted files, in order. */
    onAdd?: (added: DraftAttachment[]) => void;
} = {}) {
    const { attachmentLimits } = usePage<SharedProps>().props;
    const [items, setItems] = useState<DraftAttachment[]>([]);
    const [notice, setNotice] = useState<string | null>(null);
    const [isDraggingFiles, setIsDraggingFiles] = useState(false);
    const [isOverDropzone, setIsOverDropzone] = useState(false);
    const [highlighted, setHighlighted] = useState<string | null>(null);
    const nextNumber = useRef(0);
    const firstNumberRef = useRef(firstNumber);
    firstNumberRef.current = firstNumber;
    /** Files whose token was deleted from the text, kept so undo can bring them back. */
    const parked = useRef<DraftAttachment[]>([]);
    const onAddRef = useRef(onAdd);
    onAddRef.current = onAdd;
    const inputRef = useRef<HTMLInputElement>(null);
    const dropzoneDepth = useRef(0);
    const itemsRef = useRef(items);
    itemsRef.current = items;

    /**
     * Add files, each getting a token in the text (via `onAdd`). A file
     * byte-for-byte the same as one already on the draft reuses it rather than
     * attaching a copy: a live one just gets another token, and one whose
     * token was deleted comes back under its old label. Pasted screenshots
     * get a fresh name and timestamp every time, so only the contents can
     * tell they are the same image.
     */
    const add = useCallback(
        async (incoming: File[]) => {
            if (!enabled || incoming.length === 0) {
                return;
            }

            // Matching reads file contents, so it runs before any state changes.
            const matches: (DraftAttachment | 'repeat' | null)[] = [];
            for (const [index, file] of incoming.entries()) {
                const known = await findSameFile(file, [...itemsRef.current, ...parked.current]);
                const repeat = known === null && (await findSameFile(file, incoming.slice(0, index).map((earlier) => ({ file: earlier }))));
                matches.push(known ?? (repeat ? 'repeat' : null));
            }

            const current = itemsRef.current;
            const problems: string[] = [];
            const accepted: DraftAttachment[] = [];
            const restored: DraftAttachment[] = [];
            const placed: DraftAttachment[] = [];

            for (const [index, file] of incoming.entries()) {
                const match = matches[index];

                if (match === 'repeat') {
                    continue;
                }

                if (match !== null && parked.current.includes(match)) {
                    if (current.length + accepted.length + restored.length >= attachmentLimits.maxFiles) {
                        problems.push(`Up to ${attachmentLimits.maxFiles} files per message.`);
                        break;
                    }
                    restored.push(match);
                    placed.push(match);
                    continue;
                }

                if (match !== null) {
                    placed.push(match);
                    continue;
                }

                if (file.size > attachmentLimits.maxFileBytes) {
                    problems.push(`${file.name} is over ${formatBytes(attachmentLimits.maxFileBytes)}.`);
                    continue;
                }

                if (current.length + accepted.length + restored.length >= attachmentLimits.maxFiles) {
                    problems.push(`Up to ${attachmentLimits.maxFiles} files per message.`);
                    break;
                }

                const isImage = IMAGE_TYPES.includes(file.type);
                const kind = previewKindOf(file.type, file.name);
                const objectUrl = kind ? URL.createObjectURL(file) : null;
                const number = Math.max(nextNumber.current, firstNumberRef.current);
                nextNumber.current = number + 1;

                const item: DraftAttachment = {
                    key: `attachment-${nextKey++}`,
                    file,
                    ref: `${isImage ? 'Image' : 'File'} #${number}`,
                    previewUrl: isImage ? objectUrl : null,
                    previewKind: kind,
                    objectUrl,
                };
                accepted.push(item);
                placed.push(item);
            }

            setNotice(problems.length > 0 ? problems.join(' ') : null);

            if (restored.length > 0 || accepted.length > 0) {
                parked.current = parked.current.filter((item) => !restored.includes(item));
                itemsRef.current = [...current, ...restored, ...accepted].sort((a, b) => labelNumber(a) - labelNumber(b));
                setItems(itemsRef.current);
            }

            if (placed.length > 0) {
                onAddRef.current?.(placed);
            }
        },
        [enabled, attachmentLimits.maxFileBytes, attachmentLimits.maxFiles],
    );

    const detach = useCallback((shouldRemove: (item: DraftAttachment) => boolean): DraftAttachment[] => {
        const removed = itemsRef.current.filter(shouldRemove);

        if (removed.length > 0) {
            itemsRef.current = itemsRef.current.filter((item) => !shouldRemove(item));
            setItems(itemsRef.current);
            setNotice(null);
            setHighlighted((current) => (removed.some((item) => item.ref === current) ? null : current));
        }

        return removed;
    }, []);

    /** Drop a file for good, as the tile's remove button does. */
    const remove = useCallback(
        (key: string) => detach((item) => item.key === key).forEach((item) => item.objectUrl && URL.revokeObjectURL(item.objectUrl)),
        [detach],
    );

    /** Set aside the files whose tokens left the text; {@see restoreRefs} brings them back. */
    const parkRefs = useCallback(
        (refs: string[]) => {
            parked.current = [...parked.current, ...detach((item) => refs.includes(item.ref))];
        },
        [detach],
    );

    /** Bring parked files back when their tokens reappear (undo, or the label retyped). */
    const restoreRefs = useCallback(
        (refs: string[]) => {
            const room = attachmentLimits.maxFiles - itemsRef.current.length;
            const restoring = parked.current.filter((item) => refs.includes(item.ref)).slice(0, Math.max(0, room));

            if (restoring.length === 0) {
                return;
            }

            parked.current = parked.current.filter((item) => !restoring.includes(item));
            itemsRef.current = [...itemsRef.current, ...restoring].sort((a, b) => labelNumber(a) - labelNumber(b));
            setItems(itemsRef.current);
        },
        [attachmentLimits.maxFiles],
    );

    const parkedRefs = useCallback(() => parked.current.map((item) => item.ref), []);

    const clear = useCallback(() => {
        [...itemsRef.current, ...parked.current].forEach((item) => item.objectUrl && URL.revokeObjectURL(item.objectUrl));
        itemsRef.current = [];
        parked.current = [];
        nextNumber.current = 0;
        setItems([]);
        setNotice(null);
        setHighlighted(null);
    }, []);

    // Revoke whatever previews are still alive when the composer unmounts.
    useEffect(
        () => () => [...itemsRef.current, ...parked.current].forEach((item) => item.objectUrl && URL.revokeObjectURL(item.objectUrl)),
        [],
    );

    useEffect(() => {
        if (!enabled) {
            return;
        }

        let depth = 0;

        const onEnter = (event: globalThis.DragEvent) => {
            if (hasFiles(event)) {
                depth++;
                setIsDraggingFiles(true);
            }
        };
        const onLeave = (event: globalThis.DragEvent) => {
            if (hasFiles(event) && --depth <= 0) {
                depth = 0;
                setIsDraggingFiles(false);
            }
        };
        const onOver = (event: globalThis.DragEvent) => {
            if (hasFiles(event)) {
                event.preventDefault();
                if (event.dataTransfer) {
                    event.dataTransfer.dropEffect = 'none';
                }
            }
        };
        const onDrop = (event: globalThis.DragEvent) => {
            if (hasFiles(event)) {
                event.preventDefault();
            }
            depth = 0;
            setIsDraggingFiles(false);
        };

        window.addEventListener('dragenter', onEnter);
        window.addEventListener('dragleave', onLeave);
        window.addEventListener('dragover', onOver);
        window.addEventListener('drop', onDrop);

        return () => {
            window.removeEventListener('dragenter', onEnter);
            window.removeEventListener('dragleave', onLeave);
            window.removeEventListener('dragover', onOver);
            window.removeEventListener('drop', onDrop);
        };
    }, [enabled]);

    /**
     * Files on the clipboard (a screenshot, an image copied from a page, a
     * file copied in Finder) attach instead of pasting. Rich text from an
     * office app also carries a picture of itself, so a clipboard with
     * both HTML and plain text pastes the text and ignores the image.
     */
    const onPaste = (event: ClipboardEvent) => {
        const files = Array.from(event.clipboardData.files);

        if (!enabled || files.length === 0) {
            return;
        }

        const isRichText = event.clipboardData.types.includes('text/html') && event.clipboardData.getData('text/plain').trim() !== '';

        if (isRichText) {
            return;
        }

        event.preventDefault();
        add(files.map(renamePastedImage));
    };

    const dropzoneProps = {
        onDragEnter: (event: DragEvent) => {
            if (enabled && hasFiles(event)) {
                dropzoneDepth.current++;
                setIsOverDropzone(true);
            }
        },
        onDragLeave: (event: DragEvent) => {
            if (enabled && hasFiles(event) && --dropzoneDepth.current <= 0) {
                dropzoneDepth.current = 0;
                setIsOverDropzone(false);
            }
        },
        onDragOver: (event: DragEvent) => {
            if (enabled && hasFiles(event)) {
                event.preventDefault();
                // Stops the window listener from marking this a no-drop zone.
                event.stopPropagation();
                event.dataTransfer.dropEffect = 'copy';
            }
        },
        onDrop: (event: DragEvent) => {
            dropzoneDepth.current = 0;
            setIsOverDropzone(false);
            if (enabled && hasFiles(event)) {
                event.preventDefault();
                add(Array.from(event.dataTransfer.files));
            }
        },
    };

    const inputProps = {
        ref: inputRef,
        type: 'file' as const,
        multiple: true,
        hidden: true,
        tabIndex: -1,
        onChange: (event: ChangeEvent<HTMLInputElement>) => {
            add(Array.from(event.target.files ?? []));
            // Lets the same file be picked again after it is removed.
            event.target.value = '';
        },
    };

    return {
        items,
        files: items.map((item) => item.file),
        refs: items.map((item) => item.ref),
        highlighted,
        setHighlighted,
        notice,
        limits: attachmentLimits,
        isDraggingFiles,
        isOverDropzone,
        add,
        remove,
        parkRefs,
        restoreRefs,
        parkedRefs,
        clear,
        openPicker: () => inputRef.current?.click(),
        onPaste,
        dropzoneProps,
        inputProps,
    };
}

export type AttachmentDraft = ReturnType<typeof useAttachmentDraft>;
