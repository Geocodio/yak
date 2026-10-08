import { IconButton, Tooltip, cn } from '@geocodio/console-ui';
import { File, FileArchive, FileAudio, FileCode, FileSpreadsheet, FileText, FileVideo, ImagePlus, Paperclip, X } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { formatBytes } from '@/lib/format';
import type { AttachmentData, MediaItem } from '@/types/tasks';
import type { AttachmentDraft } from './useAttachmentDraft';

const CODE_EXTENSIONS = ['js', 'jsx', 'ts', 'tsx', 'php', 'py', 'rb', 'go', 'rs', 'java', 'json', 'xml', 'yml', 'yaml', 'html', 'css', 'sql', 'sh'];

function FileIcon({ name, mimeType }: { name: string; mimeType: string }) {
    const extension = name.split('.').pop()?.toLowerCase() ?? '';
    const props = { size: 18, strokeWidth: 1.75, 'aria-hidden': true };

    if (mimeType.startsWith('video/')) {
        return <FileVideo {...props} />;
    }
    if (mimeType.startsWith('audio/')) {
        return <FileAudio {...props} />;
    }
    if (['zip', 'gz', 'tar', 'tgz', '7z', 'rar'].includes(extension)) {
        return <FileArchive {...props} />;
    }
    if (['csv', 'tsv', 'xls', 'xlsx', 'numbers'].includes(extension)) {
        return <FileSpreadsheet {...props} />;
    }
    if (CODE_EXTENSIONS.includes(extension)) {
        return <FileCode {...props} />;
    }
    if (mimeType.startsWith('text/') || ['pdf', 'md', 'log', 'txt', 'doc', 'docx'].includes(extension)) {
        return <FileText {...props} />;
    }
    return <File {...props} />;
}

function extensionLabel(name: string): string | null {
    const parts = name.split('.');
    return parts.length > 1 ? parts.pop()!.toUpperCase() : null;
}

/**
 * One attachment as a square image thumbnail or a file card. Images open
 * the lightbox; files open (download) their `href`. A remove button sits
 * on the corner when `onRemove` is given. `reference` is the label the
 * message uses for it; hovering or focusing the tile reports it through
 * `onHighlight`, and `highlighted` lifts the tile when its label is hovered.
 */
function AttachmentTile({
    name,
    size,
    mimeType,
    imageUrl,
    href,
    reference,
    highlighted = false,
    onHighlight,
    onOpen,
    onRemove,
    error,
    large = false,
    testId,
}: {
    name: string;
    size: number;
    mimeType: string;
    imageUrl: string | null;
    href?: string;
    reference?: string | null;
    highlighted?: boolean;
    onHighlight?: (reference: string | null) => void;
    onOpen?: () => void;
    onRemove?: () => void;
    error?: string | null;
    large?: boolean;
    testId?: string;
}) {
    const frame = cn(
        'block overflow-hidden rounded-card border bg-panel-2 shadow-card outline-none transition-[border-color,box-shadow,translate]',
        'focus-visible:ring-2 focus-visible:ring-accent hover:border-hair-strong',
        error ? 'border-fail' : 'border-hair',
        highlighted && 'border-accent ring-2 ring-accent/40 motion-safe:-translate-y-0.5',
    );
    const highlightHandlers =
        reference && onHighlight
            ? {
                  onMouseEnter: () => onHighlight(reference),
                  onMouseLeave: () => onHighlight(null),
                  onFocus: () => onHighlight(reference),
                  onBlur: () => onHighlight(null),
              }
            : {};
    const badge = reference ? `#${reference.split('#')[1]}` : null;
    const square = large ? 'size-20' : 'size-16';

    const body: ReactNode = imageUrl ? (
        <span className="relative block">
            <img src={imageUrl} alt={name} draggable={false} className={cn(square, 'object-cover')} />
            {badge && (
                <span
                    className={cn(
                        'absolute bottom-1 left-1 rounded-chip px-1 text-[10px] font-medium leading-4 tabular-nums transition-colors',
                        highlighted ? 'bg-accent text-accent-ink' : 'bg-black/60 text-white',
                    )}
                >
                    {badge}
                </span>
            )}
        </span>
    ) : (
        <div className={cn('flex w-48 items-center gap-2.5 px-3', large ? 'h-20' : 'h-16')}>
            <span className="flex size-8 shrink-0 items-center justify-center rounded-control bg-panel text-muted">
                <FileIcon name={name} mimeType={mimeType} />
            </span>
            <span className="min-w-0 text-left">
                <span className="block truncate text-[12px] font-medium text-body">{name}</span>
                <span className="block text-[11px] text-faint">
                    {[reference, extensionLabel(name), formatBytes(size)].filter(Boolean).join(' · ')}
                </span>
            </span>
        </div>
    );

    const label = onOpen ? `View ${name}` : `Download ${name}`;
    const content = onOpen ? (
        <button type="button" onClick={onOpen} className={frame} aria-label={label} title={name}>
            {body}
        </button>
    ) : href ? (
        <a href={href} download={name} className={frame} aria-label={label} title={name}>
            {body}
        </a>
    ) : (
        <div className={frame} title={name}>
            {body}
        </div>
    );

    return (
        <div
            className="group relative row-enter"
            data-testid={testId}
            data-reference={reference ?? undefined}
            data-highlighted={highlighted ? '' : undefined}
            {...highlightHandlers}
        >
            {error ? (
                <Tooltip label={error}>
                    <div>{content}</div>
                </Tooltip>
            ) : (
                content
            )}
            {onRemove && (
                <button
                    type="button"
                    onClick={onRemove}
                    aria-label={`Remove ${name}`}
                    className={cn(
                        'absolute -right-1.5 -top-1.5 flex size-5 items-center justify-center rounded-pill border border-app bg-body text-app shadow-card',
                        'opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100 pointer-coarse:opacity-100',
                        error && 'opacity-100',
                    )}
                    data-testid="attachment-remove"
                >
                    <X size={11} strokeWidth={2.5} aria-hidden />
                </button>
            )}
        </div>
    );
}

/** Sent attachments the lightbox can show, in order, as its gallery. */
function sentMedia(attachments: AttachmentData[]): { media: MediaItem[]; ids: number[] } {
    const previewable = attachments.filter((attachment) => attachment.previewKind !== null);

    return {
        ids: previewable.map((attachment) => attachment.id),
        media: previewable.map((attachment) => ({
            id: attachment.id,
            kind: attachment.previewKind!,
            url: attachment.url,
            thumbUrl: null,
            caption: attachment.reference ? `${attachment.reference} · ${attachment.name}` : attachment.name,
            downloadUrl: attachment.downloadUrl,
            fileName: attachment.name,
        })),
    };
}

/**
 * The files queued on a composer, with an upload progress bar while the
 * message is sending. `errors` are the server's `attachments.N` messages.
 */
export function DraftAttachments({
    draft,
    errors,
    progress,
    onOpenMedia,
}: {
    draft: AttachmentDraft;
    errors: Record<string, string | undefined>;
    progress: number | null;
    onOpenMedia: (media: MediaItem[], index: number) => void;
}) {
    const listError = errors.attachments;
    const notice = draft.notice ?? listError ?? null;

    if (draft.items.length === 0 && notice === null) {
        return null;
    }

    const previewable = draft.items.filter((item) => item.objectUrl !== null && item.previewKind !== null);
    const media: MediaItem[] = previewable.map((item, index) => ({
        id: index,
        kind: item.previewKind!,
        url: item.objectUrl!,
        thumbUrl: null,
        caption: `${item.ref} · ${item.file.name}`,
        downloadUrl: item.objectUrl,
        downloadName: item.file.name,
        fileName: item.file.name,
    }));

    return (
        <div className="px-3 pb-2" data-testid="draft-attachments">
            {draft.items.length > 0 && (
                <div className="flex flex-wrap gap-2 pt-1.5">
                    {draft.items.map((item, index) => (
                        <AttachmentTile
                            key={item.key}
                            name={item.file.name}
                            size={item.file.size}
                            mimeType={item.file.type}
                            imageUrl={item.previewUrl}
                            reference={item.ref}
                            highlighted={draft.highlighted === item.ref}
                            onHighlight={draft.setHighlighted}
                            error={errors[`attachments.${index}`]}
                            onOpen={item.objectUrl ? () => onOpenMedia(media, previewable.indexOf(item)) : undefined}
                            onRemove={progress === null ? () => draft.remove(item.key) : undefined}
                            testId="draft-attachment"
                        />
                    ))}
                </div>
            )}
            {progress !== null && (
                <div className="mt-2 h-1 overflow-hidden rounded-pill bg-panel-2" role="progressbar" aria-label="Uploading attachments" aria-valuenow={progress}>
                    <div className="h-full rounded-pill bg-accent transition-[width] duration-200" style={{ width: `${progress}%` }} />
                </div>
            )}
            {notice !== null && (
                <p className="mt-1.5 text-[12px] text-fail" role="alert" data-testid="attachment-notice">
                    {notice}
                </p>
            )}
        </div>
    );
}

/** Files that went out with a message, shown under it in the thread. */
export function SentAttachments({
    attachments,
    onOpenMedia,
    highlighted = null,
    onHighlight,
}: {
    attachments: AttachmentData[];
    onOpenMedia: (media: MediaItem[], index: number) => void;
    highlighted?: string | null;
    onHighlight?: (reference: string | null) => void;
}) {
    if (attachments.length === 0) {
        return null;
    }

    const { media, ids } = sentMedia(attachments);

    return (
        <div className="mt-2" data-testid="sent-attachments">
            <div className="flex flex-wrap gap-2">
                {attachments.map((attachment) => (
                    <AttachmentTile
                        key={attachment.id}
                        name={attachment.name}
                        size={attachment.size}
                        mimeType={attachment.mimeType}
                        imageUrl={attachment.isImage ? attachment.url : null}
                        href={attachment.downloadUrl}
                        reference={attachment.reference}
                        highlighted={attachment.reference !== null && attachment.reference === highlighted}
                        onHighlight={onHighlight}
                        onOpen={attachment.previewKind ? () => onOpenMedia(media, ids.indexOf(attachment.id)) : undefined}
                        large
                        testId={`sent-attachment-${attachment.id}`}
                    />
                ))}
            </div>
        </div>
    );
}

/**
 * A sent message and its files, linked both ways: hovering a `[Image #1]`
 * chip in the text (rendered server-side by `AttachmentReferences`) lifts
 * its tile, hovering a tile lights up its chips, and clicking a chip opens
 * the file. `children` is the message body.
 */
export function MessageWithAttachments({
    attachments,
    onOpenMedia,
    children,
}: {
    attachments: AttachmentData[];
    onOpenMedia: (media: MediaItem[], index: number) => void;
    children: ReactNode;
}) {
    const [highlighted, setHighlighted] = useState<string | null>(null);
    const bodyRef = useRef<HTMLDivElement>(null);

    // The chips live in server-rendered HTML, so they're marked directly.
    useEffect(() => {
        bodyRef.current?.querySelectorAll<HTMLElement>('[data-attachment-ref]').forEach((chip) => {
            chip.toggleAttribute('data-highlighted', chip.dataset.attachmentRef === highlighted);
        });
    });

    const referenceAt = (target: EventTarget): string | null =>
        target instanceof Element ? (target.closest<HTMLElement>('[data-attachment-ref]')?.dataset.attachmentRef ?? null) : null;

    const open = (reference: string) => {
        const attachment = attachments.find((candidate) => candidate.reference === reference);

        if (!attachment) {
            return;
        }

        if (attachment.previewKind) {
            const { media, ids } = sentMedia(attachments);
            onOpenMedia(media, ids.indexOf(attachment.id));
        } else {
            window.location.assign(attachment.downloadUrl);
        }
    };

    return (
        <>
            <div
                ref={bodyRef}
                onMouseOver={(event) => setHighlighted(referenceAt(event.target))}
                onMouseLeave={() => setHighlighted(null)}
                onClick={(event) => {
                    const reference = referenceAt(event.target);
                    if (reference) {
                        open(reference);
                    }
                }}
            >
                {children}
            </div>
            <SentAttachments attachments={attachments} onOpenMedia={onOpenMedia} highlighted={highlighted} onHighlight={setHighlighted} />
        </>
    );
}

/**
 * Covers the dropzone while files are dragged over the window: a quiet
 * dashed hint anywhere on the page, a solid accent target once over it.
 */
export function AttachmentDropOverlay({ draft }: { draft: AttachmentDraft }) {
    if (!draft.isDraggingFiles && !draft.isOverDropzone) {
        return null;
    }

    return (
        <div
            className={cn(
                'pointer-events-none absolute inset-0 z-10 flex items-center justify-center gap-2 rounded-card border-2 border-dashed text-[13px] font-medium transition-colors',
                draft.isOverDropzone ? 'border-accent bg-accent-soft/95 text-accent-text' : 'border-hair-strong bg-panel/85 text-muted',
            )}
            data-testid="attachment-drop-overlay"
        >
            <ImagePlus size={16} aria-hidden />
            {draft.isOverDropzone ? 'Drop to attach' : 'Drop files here to attach them'}
        </div>
    );
}

/** The paperclip that opens the file picker, plus the hidden input it drives. */
export function AttachButton({ draft, disabled = false }: { draft: AttachmentDraft; disabled?: boolean }) {
    const count = draft.items.length;

    return (
        <>
            <input {...draft.inputProps} data-testid="attachment-input" />
            <Tooltip label="Attach files. You can also paste or drop them in.">
                <IconButton
                    label={count > 0 ? `Attach files (${count} attached)` : 'Attach files'}
                    onClick={draft.openPicker}
                    disabled={disabled || count >= draft.limits.maxFiles}
                    data-testid="attachment-button"
                >
                    <Paperclip size={15} aria-hidden />
                </IconButton>
            </Tooltip>
        </>
    );
}
